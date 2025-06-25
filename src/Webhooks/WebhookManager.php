<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Webhooks;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Events\PermissionChanged;

class WebhookManager
{
    protected array $webhooks = [];
    protected Http $http;

    public function __construct(Http $http)
    {
        $this->http = $http;
        $this->webhooks = config('openfga.webhooks', []);
    }

    /**
     * Register a webhook endpoint
     */
    public function register(string $name, string $url, array $events = [], array $headers = []): void
    {
        $this->webhooks[$name] = [
            'url' => $url,
            'events' => $events,
            'headers' => $headers,
            'active' => true,
        ];
    }

    /**
     * Unregister a webhook
     */
    public function unregister(string $name): void
    {
        unset($this->webhooks[$name]);
    }

    /**
     * Enable a webhook
     */
    public function enable(string $name): void
    {
        if (isset($this->webhooks[$name])) {
            $this->webhooks[$name]['active'] = true;
        }
    }

    /**
     * Disable a webhook
     */
    public function disable(string $name): void
    {
        if (isset($this->webhooks[$name])) {
            $this->webhooks[$name]['active'] = false;
        }
    }

    /**
     * Send webhook notifications for a permission change event
     */
    public function notifyPermissionChange(PermissionChanged $event): void
    {
        $payload = $this->buildPayload($event);

        foreach ($this->webhooks as $name => $webhook) {
            if (! $this->shouldSendWebhook($webhook, $event)) {
                continue;
            }

            $this->sendWebhook($name, $webhook, $payload);
        }
    }

    /**
     * Check if webhook should be sent for this event
     */
    protected function shouldSendWebhook(array $webhook, PermissionChanged $event): bool
    {
        // Check if webhook is active
        if (! ($webhook['active'] ?? true)) {
            return false;
        }

        // Check if webhook listens to all events or this specific event
        $events = $webhook['events'] ?? [];
        if (empty($events) || in_array('*', $events)) {
            return true;
        }

        return in_array($event->getEventType(), $events);
    }

    /**
     * Build webhook payload
     */
    protected function buildPayload(PermissionChanged $event): array
    {
        return [
            'event' => $event->getEventType(),
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'user' => $event->user,
                'relation' => $event->relation,
                'object' => $event->object,
                'action' => $event->action,
                'metadata' => $event->metadata,
            ],
            'environment' => app()->environment(),
            'application' => config('app.name'),
        ];
    }

    /**
     * Send webhook request
     */
    protected function sendWebhook(string $name, array $webhook, array $payload): void
    {
        try {
            $response = $this->http
                ->withHeaders($webhook['headers'] ?? [])
                ->timeout(config('openfga.webhooks.timeout', 5))
                ->retry(config('openfga.webhooks.retries', 3), 100)
                ->post($webhook['url'], $payload);

            if ($response->failed()) {
                Log::error('OpenFGA webhook failed', [
                    'webhook' => $name,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('OpenFGA webhook error', [
                'webhook' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get registered webhooks
     */
    public function getWebhooks(): array
    {
        return $this->webhooks;
    }
}