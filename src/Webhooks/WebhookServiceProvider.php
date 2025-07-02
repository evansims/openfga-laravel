<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Webhooks;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Listeners\WebhookEventListener;
use Override;

use function is_array;
use function is_bool;
use function is_string;

final class WebhookServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        // Only register webhooks if enabled
        $enabled = config('openfga.webhooks.enabled', false);

        if (! is_bool($enabled) || ! $enabled) {
            return;
        }

        // Load webhook routes
        $this->loadRoutesFrom(__DIR__ . '/../../routes/webhooks.php');

        // Register event listeners
        Event::subscribe(WebhookEventListener::class);

        // Register webhook configuration
        $this->registerWebhooks();
    }

    /**
     * Register services.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(WebhookManager::class);
        $this->app->singleton(WebhookProcessor::class);
    }

    /**
     * Register configured webhooks.
     *
     * @throws BindingResolutionException
     */
    private function registerWebhooks(): void
    {
        $webhookManager = $this->app->make(WebhookManager::class);

        $webhooks = config('openfga.webhooks.endpoints', []);

        if (! is_array($webhooks)) {
            return;
        }

        foreach ($webhooks as $name => $config) {
            if (! is_string($name)) {
                continue;
            }

            if (! is_array($config)) {
                continue;
            }
            $url = $config['url'] ?? null;

            if (! is_string($url)) {
                continue;
            }

            /** @var mixed $eventsRaw */
            $eventsRaw = $config['events'] ?? [];
            $events = is_array($eventsRaw) ? $eventsRaw : [];

            /** @var mixed $headersRaw */
            $headersRaw = $config['headers'] ?? [];
            $headers = is_array($headersRaw) ? $headersRaw : [];

            /** @var array<int, string> $events */
            /** @var array<string, string> $headers */
            $webhookManager->register(
                $name,
                $url,
                $events,
                $headers,
            );

            /** @var mixed $activeRaw */
            $activeRaw = $config['active'] ?? true;
            $active = is_bool($activeRaw) ? $activeRaw : true;

            if (! $active) {
                $webhookManager->disable($name);
            }
        }
    }
}
