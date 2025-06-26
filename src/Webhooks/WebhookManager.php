<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Webhooks;

use Exception;
use Illuminate\Http\Client\{Factory as Http, PendingRequest};
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Events\PermissionChanged;

use function count;
use function in_array;
use function is_array;
use function is_string;

final class WebhookManager
{
    /**
     * @var array<string, array{url: string, events: array<int, string>, headers: array<string, string>, active: bool}>
     */
    private array $webhooks = [];

    public function __construct(private readonly Http $http)
    {
        /** @var mixed $webhooks */
        $webhooks = config('openfga.webhooks', []);

        if (is_array($webhooks)) {
            // Filter out any non-array values that might be in the config
            /** @var mixed $webhook */
            foreach ($webhooks as $name => $webhook) {
                if (is_string($name) && is_array($webhook) && isset($webhook['url']) && is_string($webhook['url'])) {
                    /** @var array{url: string, events: array<int, string>, headers: array<string, string>, active: bool} $validWebhook */
                    $validWebhook = $webhook;
                    $this->webhooks[$name] = $validWebhook;
                }
            }
        }
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
     *
     * @return array<string, array{url: string, events: array<int, string>, headers: array<string, string>, active: bool}>
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
     * @param string                $name
     * @param string                $url
     * @param array<int, string>    $events
     * @param array<string, string> $headers
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
     * @param  PermissionChanged    $event
     * @return array<string, mixed>
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
     * @param string                                                                                       $name
     * @param array{url: string, events: array<int, string>, headers: array<string, string>, active: bool} $webhook
     * @param array<string, mixed>                                                                         $payload
     */
    private function sendWebhook(string $name, array $webhook, array $payload): void
    {
        try {
            /** @var mixed $timeout */
            $timeout = config('openfga.webhooks.timeout', 5);

            /** @var mixed $retries */
            $retries = config('openfga.webhooks.retries', 3);

            /** @var PendingRequest $client */
            $client = $this->http->withHeaders($webhook['headers']);

            $response = $client
                ->timeout(is_numeric($timeout) ? (int) $timeout : 5)
                ->retry(is_numeric($retries) ? (int) $retries : 3, 100)
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
     * @param array{url: string, events: array<int, string>, headers: array<string, string>, active: bool} $webhook
     * @param PermissionChanged                                                                            $event
     */
    private function shouldSendWebhook(array $webhook, PermissionChanged $event): bool
    {
        // Check if webhook is active
        if (! ($webhook['active'] ?? true)) {
            return false;
        }

        // Check if webhook listens to all events or this specific event
        $events = $webhook['events'] ?? [];

        if (0 === count($events) || in_array('*', $events, true)) {
            return true;
        }

        return in_array($event->getEventType(), $events, true);
    }
}
