<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Feature;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Event;
use OpenFGA\Laravel\Events\{PermissionChanged, PermissionGranted, PermissionRevoked};
use OpenFGA\Laravel\Listeners\WebhookEventListener;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\Webhooks\WebhookManager;

class WebhookTest extends TestCase
{
    protected WebhookManager $webhookManager;
    protected Http $http;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->http = $this->mock(Http::class);
        $this->webhookManager = new WebhookManager($this->http);
    }

    public function test_webhook_registration()
    {
        $this->webhookManager->register(
            'test',
            'https://example.com/webhook',
            ['permission.granted'],
            ['Authorization' => 'Bearer token']
        );

        $webhooks = $this->webhookManager->getWebhooks();
        
        $this->assertArrayHasKey('test', $webhooks);
        $this->assertEquals('https://example.com/webhook', $webhooks['test']['url']);
        $this->assertEquals(['permission.granted'], $webhooks['test']['events']);
        $this->assertTrue($webhooks['test']['active']);
    }

    public function test_webhook_enable_disable()
    {
        $this->webhookManager->register('test', 'https://example.com/webhook');
        
        // Disable
        $this->webhookManager->disable('test');
        $webhooks = $this->webhookManager->getWebhooks();
        $this->assertFalse($webhooks['test']['active']);
        
        // Enable
        $this->webhookManager->enable('test');
        $webhooks = $this->webhookManager->getWebhooks();
        $this->assertTrue($webhooks['test']['active']);
    }

    public function test_webhook_unregister()
    {
        $this->webhookManager->register('test', 'https://example.com/webhook');
        $this->assertArrayHasKey('test', $this->webhookManager->getWebhooks());
        
        $this->webhookManager->unregister('test');
        $this->assertArrayNotHasKey('test', $this->webhookManager->getWebhooks());
    }

    public function test_webhook_notification_sent()
    {
        $this->webhookManager->register(
            'test',
            'https://example.com/webhook',
            ['permission.granted']
        );

        $event = new PermissionChanged(
            user: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            action: 'granted'
        );

        // Mock HTTP client
        $mockResponse = $this->mock(\Illuminate\Http\Client\Response::class);
        $mockResponse->shouldReceive('failed')->andReturn(false);
        
        $mockPendingRequest = $this->mock(\Illuminate\Http\Client\PendingRequest::class);
        $mockPendingRequest->shouldReceive('withHeaders')->andReturnSelf();
        $mockPendingRequest->shouldReceive('timeout')->andReturnSelf();
        $mockPendingRequest->shouldReceive('retry')->andReturnSelf();
        $mockPendingRequest->shouldReceive('post')
            ->once()
            ->with('https://example.com/webhook', \Mockery::type('array'))
            ->andReturn($mockResponse);
        
        $this->http->shouldReceive('withHeaders')->andReturn($mockPendingRequest);

        $this->webhookManager->notifyPermissionChange($event);
    }

    public function test_webhook_filters_by_event_type()
    {
        $this->webhookManager->register(
            'test',
            'https://example.com/webhook',
            ['permission.revoked'] // Only listen to revoked events
        );

        // This should NOT trigger webhook (granted != revoked)
        $event = new PermissionChanged(
            user: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            action: 'granted'
        );

        // Mock should not receive any calls
        $this->http->shouldNotReceive('withHeaders');

        $this->webhookManager->notifyPermissionChange($event);
    }

    public function test_webhook_listener_integration()
    {
        Event::fake();

        // Configure webhook in config
        config(['openfga.webhooks.enabled' => true]);
        config(['openfga.webhooks.endpoints.test' => [
            'url' => 'https://example.com/webhook',
            'events' => ['permission.granted'],
        ]]);

        // Trigger an event
        event(new PermissionGranted(
            user: 'user:123',
            relation: 'editor',
            object: 'document:789'
        ));

        Event::assertListening(
            PermissionGranted::class,
            WebhookEventListener::class
        );
    }

    public function test_webhook_command_list()
    {
        $this->app->singleton(WebhookManager::class, fn() => $this->webhookManager);
        
        $this->webhookManager->register('test1', 'https://example.com/1');
        $this->webhookManager->register('test2', 'https://example.com/2', ['permission.granted']);

        $this->artisan('openfga:webhook', ['action' => 'list'])
            ->expectsTable(
                ['Name', 'URL', 'Events', 'Status'],
                [
                    ['test1', 'https://example.com/1', '*', 'Active'],
                    ['test2', 'https://example.com/2', 'permission.granted', 'Active'],
                ]
            )
            ->assertSuccessful();
    }

    public function test_webhook_command_test()
    {
        $this->app->singleton(WebhookManager::class, fn() => $this->webhookManager);

        // Mock successful response
        $mockResponse = $this->mock(\Illuminate\Http\Client\Response::class);
        $mockResponse->shouldReceive('failed')->andReturn(false);
        
        $mockPendingRequest = $this->mock(\Illuminate\Http\Client\PendingRequest::class);
        $mockPendingRequest->shouldReceive('withHeaders')->andReturnSelf();
        $mockPendingRequest->shouldReceive('timeout')->andReturnSelf();
        $mockPendingRequest->shouldReceive('retry')->andReturnSelf();
        $mockPendingRequest->shouldReceive('post')->andReturn($mockResponse);
        
        $this->http->shouldReceive('withHeaders')->andReturn($mockPendingRequest);

        $this->artisan('openfga:webhook', [
            'action' => 'test',
            '--url' => 'https://example.com/test',
        ])
            ->expectsOutputToContain('Testing webhook: https://example.com/test')
            ->expectsOutputToContain('✅ Webhook test completed')
            ->assertSuccessful();
    }
}