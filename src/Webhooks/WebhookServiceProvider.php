<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Webhooks;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Listeners\WebhookEventListener;
use Override;

final class WebhookServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Only register webhooks if enabled
        if (! config('openfga.webhooks.enabled', false)) {
            return;
        }

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
    }

    /**
     * Register configured webhooks.
     */
    protected function registerWebhooks(): void
    {
        $webhookManager = $this->app->make(WebhookManager::class);
        $webhooks = config('openfga.webhooks.endpoints', []);

        foreach ($webhooks as $name => $config) {
            $webhookManager->register(
                $name,
                $config['url'],
                $config['events'] ?? [],
                $config['headers'] ?? [],
            );

            if (isset($config['active']) && ! $config['active']) {
                $webhookManager->disable($name);
            }
        }
    }
}
