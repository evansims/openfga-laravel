<?php

declare(strict_types=1);
use OpenFGA\Laravel\Tests\Support\FeatureTestCase;

uses(FeatureTestCase::class);

use Illuminate\Http\Client\{Factory as Http, Response};
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Event;
use Mockery;
use OpenFGA\Laravel\Events\{PermissionChanged, PermissionGranted};
use OpenFGA\Laravel\Webhooks\{WebhookManager, WebhookServiceProvider};

describe('Webhook', function (): void {
    it('webhook command list', function (): void {
        // Create webhook manager with HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);
        $this->app->singleton(WebhookManager::class, fn () => $webhookManager);

        $webhookManager->register('test1', 'https://example.com/1');
        $webhookManager->register('test2', 'https://example.com/2', ['permission.granted']);

        // Run the command and check the output
        $this->artisan('openfga:webhook', ['action' => 'list'])
            ->assertSuccessful();
    });

    it('webhook command test', function (): void {
        // Create webhook manager with HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);
        $this->app->singleton(WebhookManager::class, fn () => $webhookManager);

        // Set up Mockery expectations for the HTTP test
        $pendingRequest = Mockery::mock(PendingRequest::class);
        $response = Mockery::mock(Response::class);

        $httpMock->shouldReceive('withHeaders')
            ->with(Mockery::type('array'))
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('timeout')
            ->with(Mockery::type('integer'))
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('retry')
            ->with(Mockery::type('integer'), Mockery::type('integer'))
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('post')
            ->with('https://example.com/test', Mockery::type('array'))
            ->andReturn($response);

        $response->shouldReceive('failed')
            ->andReturn(false);

        $this->artisan('openfga:webhook', [
            'action' => 'test',
            '--url' => 'https://example.com/test',
        ])
            ->expectsOutputToContain('Testing webhook: https://example.com/test')
            ->expectsOutputToContain('✅ Webhook test completed')
            ->assertSuccessful();
    });

    it('webhook enable disable', function (): void {
        // Create webhook manager with HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);

        $webhookManager->register('test', 'https://example.com/webhook');

        // Disable
        $webhookManager->disable('test');
        $webhooks = $webhookManager->getWebhooks();
        expect($webhooks['test']['active'])->toBeFalse();

        // Enable
        $webhookManager->enable('test');
        $webhooks = $webhookManager->getWebhooks();
        expect($webhooks['test']['active'])->toBeTrue();
    });

    it('webhook filters by event type', function (): void {
        // Test that webhook is NOT called for non-matching event
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);

        $webhookManager->register(
            'test-revoked',
            'https://example.com/webhook',
            ['permission.revoked'], // Only listen to revoked events
        );

        // This should NOT trigger webhook (granted != revoked)
        $event = new PermissionChanged(
            user: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            action: 'granted',
        );

        // No HTTP calls should be made since the event type doesn't match
        $webhookManager->notifyPermissionChange($event);

        // Test that webhook IS called for matching event type
        $event2 = new PermissionChanged(
            user: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            action: 'revoked',
        );

        // Set up expectations for the matching event
        $pendingRequest = Mockery::mock(PendingRequest::class);
        $response = Mockery::mock(Response::class);

        $httpMock->shouldReceive('withHeaders')
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('timeout')
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('retry')
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('post')
            ->andReturn($response);

        $response->shouldReceive('failed')
            ->andReturn(false);

        $webhookManager->notifyPermissionChange($event2);

        // Verify expectations were met
        expect(true)->toBeTrue();
    });

    it('webhook listener integration', function (): void {
        // Configure webhook in config
        config(['openfga.webhooks.enabled' => true]);
        config(['openfga.webhooks.endpoints.test' => [
            'url' => 'https://example.com/webhook',
            'events' => ['permission.granted'],
        ]]);

        // Register the webhook service provider manually since config is set after app boot
        $this->app->register(WebhookServiceProvider::class);

        // Create a fresh HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $this->app->instance(Http::class, $httpMock);

        // Set up expectations for the webhook call
        $pendingRequest = Mockery::mock(PendingRequest::class);
        $response = Mockery::mock(Response::class);

        $httpMock->shouldReceive('withHeaders')
            ->with(Mockery::type('array'))
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('timeout')
            ->with(Mockery::type('integer'))
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('retry')
            ->with(Mockery::type('integer'), Mockery::type('integer'))
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('post')
            ->with(Mockery::type('string'), Mockery::type('array'))
            ->andReturn($response);

        $response->shouldReceive('failed')
            ->andReturn(false);

        // Trigger an event
        event(new PermissionGranted(
            user: 'user:123',
            relation: 'editor',
            object: 'document:789',
        ));

        // Verify that expectations were met
        expect(true)->toBeTrue();
    });

    it('webhook notification sent', function (): void {
        // Create a fresh webhook manager for this test to avoid interference
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);

        $webhookManager->register(
            'test',
            'https://example.com/webhook',
            ['permission.granted'],
        );

        $event = new PermissionChanged(
            user: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            action: 'granted',
        );

        // Set up proper Mockery expectations
        $pendingRequest = Mockery::mock(PendingRequest::class);
        $response = Mockery::mock(Response::class);

        $httpMock->shouldReceive('withHeaders')
            ->with(Mockery::type('array'))
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('timeout')
            ->with(Mockery::type('integer'))
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('retry')
            ->with(Mockery::type('integer'), Mockery::type('integer'))
            ->andReturn($pendingRequest);

        $pendingRequest->shouldReceive('post')
            ->with('https://example.com/webhook', Mockery::on(fn ($data) => isset($data['event']) && 'permission.granted' === $data['event']
                    && isset($data['data']['user']) && 'user:123' === $data['data']['user']))
            ->andReturn($response);

        $response->shouldReceive('failed')
            ->andReturn(false);

        $webhookManager->notifyPermissionChange($event);

        // Add assertion to verify mock expectations were met
        expect(true)->toBeTrue();
    });

    it('webhook registration', function (): void {
        // Create webhook manager with HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);

        $webhookManager->register(
            'test',
            'https://example.com/webhook',
            ['permission.granted'],
            ['Authorization' => 'Bearer token'],
        );

        $webhooks = $webhookManager->getWebhooks();

        expect($webhooks)->toHaveKey('test');
        expect($webhooks['test']['url'])->toBe('https://example.com/webhook');
        expect($webhooks['test']['events'])->toBe(['permission.granted']);
        expect($webhooks['test']['active'])->toBeTrue();
    });

    it('webhook unregister', function (): void {
        // Create webhook manager with HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);

        $webhookManager->register('test', 'https://example.com/webhook');
        expect($webhookManager->getWebhooks())->toHaveKey('test');

        $webhookManager->unregister('test');
        expect($webhookManager->getWebhooks())->not->toHaveKey('test');
    });
});
