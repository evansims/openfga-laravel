<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Feature;

use Illuminate\Http\Client\{Factory as Http, Response};
use Illuminate\Support\Facades\Event;
use Mockery;
use OpenFGA\Laravel\Events\{PermissionChanged, PermissionGranted};
use OpenFGA\Laravel\Listeners\WebhookEventListener;
use OpenFGA\Laravel\Tests\FeatureTestCase;
use OpenFGA\Laravel\Webhooks\WebhookManager;

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
        $command = $this->artisan('openfga:webhook', ['action' => 'list']);
        
        // Dump output for debugging
        $command->assertSuccessful();
        
        // Check that the table headers are present
        $command->expectsOutputToContain('Name');
        $command->expectsOutputToContain('URL');
        $command->expectsOutputToContain('test1');
        $command->expectsOutputToContain('https://example.com/1');
        $command->expectsOutputToContain('test2');
        $command->expectsOutputToContain('https://example.com/2');
    }

    public function test_webhook_command_test(): void
    {
        // Create webhook manager with HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);
        $this->app->singleton(WebhookManager::class, fn () => $webhookManager);

        // Set up Mockery expectations for the HTTP test
        $pendingRequest = Mockery::mock(\Illuminate\Http\Client\PendingRequest::class);
        $response = Mockery::mock(\Illuminate\Http\Client\Response::class);
        
        $httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest);
            
        $pendingRequest->shouldReceive('timeout')
            ->once()
            ->andReturn($pendingRequest);
            
        $pendingRequest->shouldReceive('retry')
            ->once()
            ->andReturn($pendingRequest);
            
        $pendingRequest->shouldReceive('post')
            ->once()
            ->with('https://example.com/test', Mockery::type('array'))
            ->andReturn($response);
            
        $response->shouldReceive('failed')
            ->once()
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
        // Create webhook manager with HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $webhookManager = new WebhookManager($httpMock);
        
        $webhookManager->register(
            'test',
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

        // Expect NO HTTP calls since the event type doesn't match
        $httpMock->shouldNotReceive('withHeaders');

        $webhookManager->notifyPermissionChange($event);
        
        // Verify expectations were met
        $this->addToAssertionCount(1);
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
        $this->app->register(\OpenFGA\Laravel\Webhooks\WebhookServiceProvider::class);

        // Create a fresh HTTP mock for this test
        $httpMock = Mockery::mock(Http::class);
        $this->app->instance(Http::class, $httpMock);
        
        // Set up expectations for the webhook call
        $pendingRequest = Mockery::mock(\Illuminate\Http\Client\PendingRequest::class);
        $response = Mockery::mock(\Illuminate\Http\Client\Response::class);
        
        $httpMock->shouldReceive('withHeaders')
            ->once()
            ->andReturn($pendingRequest);
            
        $pendingRequest->shouldReceive('timeout')
            ->once()
            ->andReturn($pendingRequest);
            
        $pendingRequest->shouldReceive('retry')
            ->once()
            ->andReturn($pendingRequest);
            
        $pendingRequest->shouldReceive('post')
            ->once()
            ->andReturn($response);
            
        $response->shouldReceive('failed')
            ->once()
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
        $pendingRequest = Mockery::mock(\Illuminate\Http\Client\PendingRequest::class);
        $response = Mockery::mock(\Illuminate\Http\Client\Response::class);
        
        $httpMock->shouldReceive('withHeaders')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturn($pendingRequest);
            
        $pendingRequest->shouldReceive('timeout')
            ->once()
            ->with(30)
            ->andReturn($pendingRequest);
            
        $pendingRequest->shouldReceive('retry')
            ->once()
            ->with(3, 100)
            ->andReturn($pendingRequest);
            
        $pendingRequest->shouldReceive('post')
            ->once()
            ->with('https://example.com/webhook', Mockery::on(function ($data) {
                return isset($data['event']) && $data['event'] === 'permission.granted'
                    && isset($data['data']['user']) && $data['data']['user'] === 'user:123';
            }))
            ->andReturn($response);
            
        $response->shouldReceive('failed')
            ->once()
            ->andReturn(false);

        $webhookManager->notifyPermissionChange($event);
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
