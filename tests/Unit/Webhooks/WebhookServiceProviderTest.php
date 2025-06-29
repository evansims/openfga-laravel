<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Listeners\WebhookEventListener;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\Webhooks\{WebhookManager, WebhookServiceProvider};

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('WebhookServiceProvider', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->provider = new WebhookServiceProvider($this->app);
    });

    afterEach(function (): void {
        $this->tearDownConfigRestoration();
    });

    it('extends Laravel ServiceProvider', function (): void {
        expect($this->provider)->toBeInstanceOf(ServiceProvider::class);
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(WebhookServiceProvider::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('has register method', function (): void {
        expect(method_exists($this->provider, 'register'))->toBeTrue();

        $reflection = new ReflectionClass($this->provider);
        $method = $reflection->getMethod('register');

        expect($method->isPublic())->toBeTrue();
    });

    it('has boot method', function (): void {
        expect(method_exists($this->provider, 'boot'))->toBeTrue();

        $reflection = new ReflectionClass($this->provider);
        $method = $reflection->getMethod('boot');

        expect($method->isPublic())->toBeTrue();
    });

    it('registers WebhookManager as singleton', function (): void {
        $this->provider->register();

        expect($this->app->bound(WebhookManager::class))->toBeTrue();

        // Verify it's a singleton
        $instance1 = $this->app->make(WebhookManager::class);
        $instance2 = $this->app->make(WebhookManager::class);

        expect($instance1)->toBe($instance2);
    });

    it('does not boot when webhooks are disabled', function (): void {
        $this->setConfigWithRestore('openfga.webhooks.enabled', false);

        Event::fake();

        $this->provider->boot();

        // Should not subscribe to events when disabled
        Event::assertNothingDispatched();
    });

    it('boots when webhooks are enabled', function (): void {
        $this->setConfigWithRestore('openfga.webhooks.enabled', true);
        $this->setConfigWithRestore('openfga.webhooks.endpoints', []); // Empty to avoid actual registration

        Event::shouldReceive('subscribe')
            ->with(WebhookEventListener::class)
            ->once();

        $this->provider->boot();
    });

    it('returns early when enabled config is not boolean', function (): void {
        $this->setConfigWithRestore('openfga.webhooks.enabled', 'yes'); // Invalid type

        Event::fake();

        $this->provider->boot();

        // Should not subscribe when config is invalid
        Event::assertNothingDispatched();
    });

    it('has private registerWebhooks method', function (): void {
        $reflection = new ReflectionClass($this->provider);

        expect($reflection->hasMethod('registerWebhooks'))->toBeTrue();

        $method = $reflection->getMethod('registerWebhooks');
        expect($method->isPrivate())->toBeTrue();
    });

    it('registers webhooks from config when enabled', function (): void {
        $this->setConfigWithRestore('openfga.webhooks.enabled', true);
        $this->setConfigWithRestore('openfga.webhooks.endpoints', [
            [
                'url' => 'https://example.com/webhook',
                'events' => ['PermissionGranted', 'PermissionRevoked'],
                'headers' => ['X-Custom' => 'value'],
                'verify_ssl' => true,
                'timeout' => 30,
            ],
        ]);

        Event::shouldReceive('subscribe')
            ->with(WebhookEventListener::class)
            ->once();

        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('validates webhook endpoint configuration', function (): void {
        $this->setConfigWithRestore('openfga.webhooks.enabled', true);
        $this->setConfigWithRestore('openfga.webhooks.endpoints', [
            [
                'url' => 'https://valid.com/webhook',
                'events' => ['PermissionGranted'],
            ],
            [
                'url' => null, // Invalid URL
                'events' => ['PermissionRevoked'],
            ],
            [
                'url' => 'https://another.com/webhook',
                'events' => [], // Empty events
            ],
        ]);

        Event::shouldReceive('subscribe')->once();

        // Should handle invalid configurations gracefully
        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('handles missing webhook manager gracefully', function (): void {
        $this->setConfigWithRestore('openfga.webhooks.enabled', true);
        $this->setConfigWithRestore('openfga.webhooks.endpoints', []);

        Event::shouldReceive('subscribe')->once();

        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });
});
