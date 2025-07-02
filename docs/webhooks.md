# Webhooks

OpenFGA Laravel supports both incoming and outgoing webhooks for real-time authorization updates.

## Table of Contents

- [Overview](#overview)
- [Incoming Webhooks](#incoming-webhooks)
- [Outgoing Webhooks](#outgoing-webhooks)
- [Configuration](#configuration)
- [Security](#security)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)

## Overview

The webhook system allows your application to:
- **Receive notifications** from OpenFGA when authorization data changes
- **Send notifications** to external systems when permissions are modified
- **Invalidate caches** automatically for better data consistency
- **React in real-time** to authorization changes

## Incoming Webhooks

Incoming webhooks allow OpenFGA to notify your application when authorization data changes.

### Setup

1. **Enable webhooks** in your `.env` file:
```env
OPENFGA_WEBHOOKS_ENABLED=true
OPENFGA_WEBHOOK_SECRET=your-secret-key
```

2. **Configure OpenFGA** to send webhooks to your endpoint:
```
https://your-app.com/openfga/webhook
```

3. **Ensure the route is accessible** from OpenFGA's servers.

### Webhook Events

The package handles the following webhook events from OpenFGA:

#### Authorization Model Write
Triggered when the authorization model is updated.
```json
{
  "type": "authorization_model_write",
  "data": {
    "store_id": "01HQMVAH3R8X123456789",
    "model_id": "01HQMVAH3R8X987654321"
  }
}
```

#### Tuple Write
Triggered when a relationship tuple is created or updated.
```json
{
  "type": "tuple_write",
  "data": {
    "user": "user:123",
    "relation": "editor",
    "object": "document:456"
  }
}
```

#### Tuple Delete
Triggered when a relationship tuple is deleted.
```json
{
  "type": "tuple_delete",
  "data": {
    "user": "user:123",
    "relation": "editor",
    "object": "document:456"
  }
}
```

### Handling Webhooks

When a webhook is received, the package automatically:

1. **Verifies the signature** (if configured)
2. **Validates the payload**
3. **Invalidates relevant caches**
4. **Dispatches events** for custom handling

You can listen to webhook events in your application:

```php
use OpenFGA\Laravel\Events\WebhookReceived;
use Illuminate\Support\Facades\Event;

Event::listen(WebhookReceived::class, function (WebhookReceived $event) {
    Log::info('Webhook received', [
        'type' => $event->type,
        'data' => $event->data,
    ]);
    
    // Custom handling based on webhook type
    match ($event->type) {
        'tuple_write' => $this->handleTupleWrite($event->data),
        'tuple_delete' => $this->handleTupleDelete($event->data),
        default => null,
    };
});
```

## Outgoing Webhooks

Outgoing webhooks notify external systems when permissions change in your application.

### Configuration

Configure endpoints in `config/openfga.php`:

```php
'webhooks' => [
    'enabled' => env('OPENFGA_WEBHOOKS_ENABLED', false),
    'timeout' => env('OPENFGA_WEBHOOK_TIMEOUT', 5),
    'retries' => env('OPENFGA_WEBHOOK_RETRIES', 3),
    
    'endpoints' => [
        'audit_log' => [
            'url' => 'https://audit.example.com/webhook',
            'headers' => [
                'Authorization' => 'Bearer ' . env('AUDIT_WEBHOOK_TOKEN'),
                'X-Service-Name' => 'openfga-laravel',
            ],
            'events' => ['permission.granted', 'permission.revoked'],
            'active' => true,
        ],
        
        'slack' => [
            'url' => env('SLACK_WEBHOOK_URL'),
            'events' => ['*'], // All events
            'active' => true,
        ],
    ],
],
```

### Webhook Payload

Outgoing webhooks send the following payload:

```json
{
  "event": "permission.granted",
  "timestamp": "2024-01-20T10:30:00Z",
  "data": {
    "user": "user:123",
    "relation": "editor",
    "object": "document:456",
    "action": "grant",
    "metadata": {
      "ip": "192.168.1.100",
      "user_agent": "Mozilla/5.0...",
      "request_id": "abc-123"
    }
  },
  "environment": "production",
  "application": "My App"
}
```

### Programmatic Management

You can manage webhooks programmatically:

```php
use OpenFGA\Laravel\Webhooks\WebhookManager;

$webhooks = app(WebhookManager::class);

// Register a new webhook
$webhooks->register('custom', 'https://example.com/hook', [
    'permission.granted',
    'permission.revoked'
], [
    'Authorization' => 'Bearer token',
]);

// Disable a webhook temporarily
$webhooks->disable('custom');

// Enable a webhook
$webhooks->enable('custom');

// Remove a webhook
$webhooks->unregister('custom');

// Get all registered webhooks
$registered = $webhooks->getWebhooks();
```

## Configuration

### Environment Variables

```env
# Enable/disable webhooks
OPENFGA_WEBHOOKS_ENABLED=true

# Incoming webhook secret for signature verification
OPENFGA_WEBHOOK_SECRET=your-secret-key

# Outgoing webhook configuration
OPENFGA_WEBHOOK_TIMEOUT=5
OPENFGA_WEBHOOK_RETRIES=3
OPENFGA_WEBHOOK_SEND_CHECKS=false

# Example outgoing webhook
AUDIT_WEBHOOK_URL=https://audit.example.com/webhook
AUDIT_WEBHOOK_TOKEN=your-audit-token
```

### Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| `webhooks.enabled` | Enable/disable webhook functionality | `false` |
| `webhooks.secret` | Secret for verifying incoming webhooks | `null` |
| `webhooks.timeout` | Timeout for outgoing webhook requests (seconds) | `5` |
| `webhooks.retries` | Number of retries for failed webhook requests | `3` |
| `webhooks.send_check_events` | Send webhooks for permission check events | `false` |

## Security

### Signature Verification

Incoming webhooks are verified using HMAC-SHA256 signatures:

1. OpenFGA signs the payload with your shared secret
2. The signature is sent in the `X-OpenFGA-Signature` header
3. The package verifies the signature before processing

### Best Practices

1. **Always use HTTPS** for webhook endpoints
2. **Set a strong webhook secret** and rotate it regularly
3. **Validate IP addresses** if OpenFGA provides a fixed IP range
4. **Implement rate limiting** to prevent abuse
5. **Log all webhook activity** for auditing
6. **Use authentication tokens** for outgoing webhooks

## Testing

### Testing Incoming Webhooks

```php
use Illuminate\Support\Facades\Event;
use OpenFGA\Laravel\Events\WebhookReceived;

public function test_incoming_webhook_invalidates_cache()
{
    Event::fake();
    
    // Enable webhooks
    config(['openfga.webhooks.enabled' => true]);
    config(['openfga.webhooks.secret' => 'test-secret']);
    
    // Create signed payload
    $payload = [
        'type' => 'tuple_write',
        'data' => [
            'user' => 'user:123',
            'relation' => 'editor',
            'object' => 'document:456',
        ],
    ];
    
    $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');
    
    // Send webhook request
    $response = $this->postJson('/openfga/webhook', $payload, [
        'X-OpenFGA-Signature' => $signature,
    ]);
    
    $response->assertOk();
    Event::assertDispatched(WebhookReceived::class);
}
```

### Testing Outgoing Webhooks

```php
use Illuminate\Support\Facades\Http;
use OpenFGA\Laravel\Events\PermissionChanged;

public function test_outgoing_webhook_sent_on_permission_change()
{
    Http::fake();
    
    // Configure webhook
    config([
        'openfga.webhooks.enabled' => true,
        'openfga.webhooks.endpoints' => [
            'test' => [
                'url' => 'https://example.com/webhook',
                'events' => ['permission.granted'],
                'active' => true,
            ],
        ],
    ]);
    
    // Trigger permission change
    Event::dispatch(new PermissionChanged(
        user: 'user:123',
        relation: 'editor',
        object: 'document:456',
        action: 'grant'
    ));
    
    // Assert webhook was sent
    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/webhook'
            && $request['event'] === 'permission.granted';
    });
}
```

## Troubleshooting

### Webhooks Not Being Received

1. **Check if webhooks are enabled**:
```php
dd(config('openfga.webhooks.enabled'));
```

2. **Verify the route is registered**:
```bash
php artisan route:list | grep webhook
```

3. **Check logs for errors**:
```bash
tail -f storage/logs/laravel.log | grep webhook
```

### Signature Verification Failing

1. **Ensure secrets match** between OpenFGA and your application
2. **Check header name** - should be `X-OpenFGA-Signature`
3. **Verify payload encoding** - should be raw JSON

### Cache Not Being Invalidated

1. **Check cache configuration**:
```php
dd(config('openfga.cache.enabled'));
```

2. **Verify cache driver supports tags** (if using tagged cache)
3. **Check webhook processing logs**

### Outgoing Webhooks Failing

1. **Check endpoint URL** is correct and accessible
2. **Verify authentication headers** are properly set
3. **Review timeout settings** - increase if needed
4. **Check retry configuration**

### Debug Mode

Enable detailed logging for webhook debugging:

```php
// In your AppServiceProvider
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Events\WebhookReceived;

Event::listen(WebhookReceived::class, function ($event) {
    Log::channel('webhook')->info('Webhook received', [
        'type' => $event->type,
        'data' => $event->data,
        'headers' => request()->headers->all(),
    ]);
});
```

## Next Steps

- Review [Caching](caching.md) for cache invalidation strategies
- See [Events](events.md) for handling permission changes
- Check [Security](security.md) for additional security considerations