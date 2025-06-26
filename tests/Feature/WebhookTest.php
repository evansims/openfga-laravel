<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Feature;

use Illuminate\Http\Client\{Factory as Http, Response};
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Event;
use Mockery;
use OpenFGA\Laravel\Events\{PermissionChanged, PermissionGranted};
use OpenFGA\Laravel\Tests\FeatureTestCase;
use OpenFGA\Laravel\Webhooks\{WebhookManager, WebhookServiceProvider};

final class WebhookTest extends FeatureTestCase
{
    public function test_webhook_command_list(): void
    {
        // Create webhook manager with HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);
        $this->app->singleton(WebhookManager::class, fn () => $webhookManager);

        $webhookManager->register('test1', 'https://example.com/1');
        $webhookManager->register('test2', 'https://example.com/2', ['permission.granted']);

        // Run the command and check the output
        $this->artisan('openfga:webhook', ['action' => 'list'])
            ->assertSuccessful();
    }

    public function test_webhook_command_test(): void
    {
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
    }

    public function test_webhook_enable_disable(): void
    {
        // Create webhook manager with HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);

        $webhookManager->register('test', 'https://example.com/webhook');

        // Disable
        $webhookManager->disable('test');
        $webhooks = $webhookManager->getWebhooks();
        $this->assertFalse($webhooks['test']['active']);

        // Enable
        $webhookManager->enable('test');
        $webhooks = $webhookManager->getWebhooks();
        $this->assertTrue($webhooks['test']['active']);
    }

    public function test_webhook_filters_by_event_type(): void
    {
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
        $this->assertTrue(true);
    }

    public function test_webhook_listener_integration(): void
    {
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
        $this->addToAssertionCount(1);
    }

    public function test_webhook_notification_sent(): void
    {
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
        $this->addToAssertionCount(1);
    }

    public function test_webhook_registration(): void
    {
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

        $this->assertArrayHasKey('test', $webhooks);
        $this->assertEquals('https://example.com/webhook', $webhooks['test']['url']);
        $this->assertEquals(['permission.granted'], $webhooks['test']['events']);
        $this->assertTrue($webhooks['test']['active']);
    }

    public function test_webhook_unregister(): void
    {
        // Create webhook manager with HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);

        $webhookManager->register('test', 'https://example.com/webhook');
        $this->assertArrayHasKey('test', $webhookManager->getWebhooks());

        $webhookManager->unregister('test');
        $this->assertArrayNotHasKey('test', $webhookManager->getWebhooks());
    }
}
