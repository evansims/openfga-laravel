<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Webhooks;

use Exception;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Events\PermissionChanged;

use function in_array;

final class WebhookManager
{
    private array $webhooks = [];

    public function __construct(protected Http $http)
    {
        $this->webhooks = config('openfga.webhooks', []);
    }

    /**
     * Disable a webhook.
     *
     * @param string $name
     */
    public function disable(string $name): void
    {
        if (isset($this->webhooks[$name])) {
            $this->webhooks[$name]['active'] = false;
        }
    }

    /**
     * Enable a webhook.
     *
     * @param string $name
     */
    public function enable(string $name): void
    {
        if (isset($this->webhooks[$name])) {
            $this->webhooks[$name]['active'] = true;
        }
    }

    /**
     * Get registered webhooks.
     */
    public function getWebhooks(): array
    {
        return $this->webhooks;
    }

    /**
     * Send webhook notifications for a permission change event.
     *
     * @param PermissionChanged $event
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
     * Register a webhook endpoint.
     *
     * @param string $name
     * @param string $url
     * @param array  $events
     * @param array  $headers
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
     * Unregister a webhook.
     *
     * @param string $name
     */
    public function unregister(string $name): void
    {
        unset($this->webhooks[$name]);
    }

    /**
     * Build webhook payload.
     *
     * @param PermissionChanged $event
     */
    private function buildPayload(PermissionChanged $event): array
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
     * Send webhook request.
     *
     * @param string $name
     * @param array  $webhook
     * @param array  $payload
     */
    private function sendWebhook(string $name, array $webhook, array $payload): void
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
        } catch (Exception $exception) {
            Log::error('OpenFGA webhook error', [
                'webhook' => $name,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Check if webhook should be sent for this event.
     *
     * @param array             $webhook
     * @param PermissionChanged $event
     */
    private function shouldSendWebhook(array $webhook, PermissionChanged $event): bool
    {
        // Check if webhook is active
        if (! ($webhook['active'] ?? true)) {
            return false;
        }

        // Check if webhook listens to all events or this specific event
        $events = $webhook['events'] ?? [];

        if (empty($events) || in_array('*', $events, true)) {
            return true;
        }

        return in_array($event->getEventType(), $events, true);
    }
}
