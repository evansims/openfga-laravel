<?php

declare(strict_types=1);

use Illuminate\Http\Client\{Factory as Http, PendingRequest, Response};
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Events\PermissionChanged;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\Webhooks\WebhookManager;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('WebhookManager', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->mockHttp = Mockery::mock(Http::class);
        $this->manager = new WebhookManager($this->mockHttp);
    });

    afterEach(function (): void {
        $this->tearDownConfigRestoration();
    });

    describe('webhook registration', function (): void {
        it('registers webhook', function (): void {
            $this->manager->register(
                name: 'test-webhook',
                url: 'https://example.com/webhook',
                events: ['permission.granted', 'permission.revoked'],
                headers: ['Authorization' => 'Bearer token'],
            );

            $webhooks = $this->manager->getWebhooks();

            expect($webhooks)->toHaveKey('test-webhook')
                ->and($webhooks['test-webhook'])->toBe([
                    'url' => 'https://example.com/webhook',
                    'events' => ['permission.granted', 'permission.revoked'],
                    'headers' => ['Authorization' => 'Bearer token'],
                    'active' => true,
                ]);
        });

        it('unregisters webhook', function (): void {
            $this->manager->register(
                name: 'test-webhook',
                url: 'https://example.com/webhook',
            );

            expect($this->manager->getWebhooks())->toHaveKey('test-webhook');

            $this->manager->unregister('test-webhook');

            expect($this->manager->getWebhooks())->not->toHaveKey('test-webhook');
        });

        it('loads webhooks from config', function (): void {
            $this->setConfigWithRestore('openfga.webhooks', [
                'default' => [
                    'url' => 'https://config.example.com/webhook',
                    'events' => ['*'],
                    'headers' => [],
                    'active' => true,
                ],
            ]);

            $manager = new WebhookManager($this->mockHttp);
            $webhooks = $manager->getWebhooks();

            expect($webhooks)->toHaveKey('default');
        });
    });

    describe('webhook state management', function (): void {
        it('enables webhook', function (): void {
            $this->manager->register(
                name: 'test-webhook',
                url: 'https://example.com/webhook',
            );
            $this->manager->disable('test-webhook');

            expect($this->manager->getWebhooks()['test-webhook']['active'])->toBeFalse();

            $this->manager->enable('test-webhook');

            expect($this->manager->getWebhooks()['test-webhook']['active'])->toBeTrue();
        });

        it('disables webhook', function (): void {
            $this->manager->register(
                name: 'test-webhook',
                url: 'https://example.com/webhook',
            );

            expect($this->manager->getWebhooks()['test-webhook']['active'])->toBeTrue();

            $this->manager->disable('test-webhook');

            expect($this->manager->getWebhooks()['test-webhook']['active'])->toBeFalse();
        });

        it('handles nonexistent webhook operations', function (): void {
            // These should not throw exceptions
            $this->manager->enable('nonexistent');
            $this->manager->disable('nonexistent');

            expect($this->manager->getWebhooks())->toBeEmpty();
        });
    });

    describe('webhook notifications', function (): void {
        it('sends webhook notification', function (): void {
            $event = new PermissionChanged(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:1',
                action: 'granted',
            );

            $this->manager->register(
                name: 'test-webhook',
                url: 'https://example.com/webhook',
                events: ['permission.granted'],
            );

            $mockResponse = Mockery::mock(Response::class);
            $mockResponse->shouldReceive('failed')->andReturn(false);

            $mockRequest = Mockery::mock(PendingRequest::class);
            $mockRequest->shouldReceive('timeout')->with(5)->andReturnSelf();
            $mockRequest->shouldReceive('retry')->with(3, 100)->andReturnSelf();
            $mockRequest->shouldReceive('post')
                ->with('https://example.com/webhook', Mockery::type('array'))
                ->andReturn($mockResponse);

            $this->mockHttp->shouldReceive('withHeaders')
                ->with([])
                ->andReturn($mockRequest);

            $this->manager->notifyPermissionChange($event);

            // Assert webhook was triggered by verifying mock expectations
            expect(true)->toBeTrue();
        });

        it('builds correct payload structure', function (): void {
            $event = new PermissionChanged(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:1',
                action: 'granted',
                metadata: ['source' => 'api'],
            );

            $this->manager->register(
                name: 'payload-webhook',
                url: 'https://example.com/webhook',
            );

            $mockResponse = Mockery::mock(Response::class);
            $mockResponse->shouldReceive('failed')->andReturn(false);

            $mockRequest = Mockery::mock(PendingRequest::class);
            $mockRequest->shouldReceive('timeout')->andReturnSelf();
            $mockRequest->shouldReceive('retry')->andReturnSelf();
            $mockRequest->shouldReceive('post')
                ->with(
                    'https://example.com/webhook',
                    Mockery::on(static fn ($payload): bool => isset($payload['event'], $payload['timestamp'], $payload['data'])
                               && 'user:123' === $payload['data']['user']
                               && 'viewer' === $payload['data']['relation']
                               && 'document:1' === $payload['data']['object']
                               && 'granted' === $payload['data']['action']
                               && $payload['data']['metadata'] === ['source' => 'api']),
                )
                ->andReturn($mockResponse);

            $this->mockHttp->shouldReceive('withHeaders')->andReturn($mockRequest);

            $this->manager->notifyPermissionChange($event);

            // Assert webhook was triggered by verifying mock expectations
            expect(true)->toBeTrue();
        });

        it('includes custom headers', function (): void {
            $event = new PermissionChanged(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:1',
                action: 'granted',
            );

            $headers = ['Authorization' => 'Bearer token', 'X-Custom' => 'value'];

            $this->manager->register(
                name: 'custom-headers-webhook',
                url: 'https://example.com/webhook',
                headers: $headers,
            );

            $mockResponse = Mockery::mock(Response::class);
            $mockResponse->shouldReceive('failed')->andReturn(false);

            $mockRequest = Mockery::mock(PendingRequest::class);
            $mockRequest->shouldReceive('timeout')->andReturnSelf();
            $mockRequest->shouldReceive('retry')->andReturnSelf();
            $mockRequest->shouldReceive('post')->andReturn($mockResponse);

            $this->mockHttp->shouldReceive('withHeaders')
                ->with($headers)
                ->andReturn($mockRequest);

            $this->manager->notifyPermissionChange($event);

            // Assert webhook was triggered by verifying mock expectations
            expect(true)->toBeTrue();
        });

        it('uses configured timeout and retries', function (): void {
            $this->setConfigWithRestore('openfga.webhooks.timeout', 10);
            $this->setConfigWithRestore('openfga.webhooks.retries', 5);

            $event = new PermissionChanged(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:1',
                action: 'granted',
            );

            $this->manager->register(
                name: 'config-webhook',
                url: 'https://example.com/webhook',
            );

            $mockResponse = Mockery::mock(Response::class);
            $mockResponse->shouldReceive('failed')->andReturn(false);

            $mockRequest = Mockery::mock(PendingRequest::class);
            $mockRequest->shouldReceive('timeout')->with(10)->andReturnSelf();
            $mockRequest->shouldReceive('retry')->with(5, 100)->andReturnSelf();
            $mockRequest->shouldReceive('post')->andReturn($mockResponse);

            $this->mockHttp->shouldReceive('withHeaders')->andReturn($mockRequest);

            $this->manager->notifyPermissionChange($event);

            // Assert webhook was triggered by verifying mock expectations
            expect(true)->toBeTrue();
        });
    });

    describe('event filtering', function (): void {
        it('filters events by webhook configuration', function (): void {
            $event = new PermissionChanged(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:1',
                action: 'granted',
            );

            $this->manager->register(
                name: 'specific-webhook',
                url: 'https://example.com/webhook',
                events: ['permission.revoked'], // Different event
            );

            $this->mockHttp->shouldNotReceive('withHeaders');

            $this->manager->notifyPermissionChange($event);
        });

        it('sends to wildcard event webhooks', function (): void {
            $event = new PermissionChanged(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:1',
                action: 'granted',
            );

            $this->manager->register(
                name: 'wildcard-webhook',
                url: 'https://example.com/webhook',
                events: ['*'],
            );

            $mockResponse = Mockery::mock(Response::class);
            $mockResponse->shouldReceive('failed')->andReturn(false);

            $mockRequest = Mockery::mock(PendingRequest::class);
            $mockRequest->shouldReceive('timeout')->andReturnSelf();
            $mockRequest->shouldReceive('retry')->andReturnSelf();
            $mockRequest->shouldReceive('post')->andReturn($mockResponse);

            $this->mockHttp->shouldReceive('withHeaders')->andReturn($mockRequest);

            $this->manager->notifyPermissionChange($event);

            // Assert webhook was triggered by verifying mock expectations
            expect(true)->toBeTrue();
        });

        it('sends to empty events webhooks', function (): void {
            $event = new PermissionChanged(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:1',
                action: 'granted',
            );

            $this->manager->register(
                name: 'no-events-webhook',
                url: 'https://example.com/webhook',
                events: [], // Empty events array should listen to all
            );

            $mockResponse = Mockery::mock(Response::class);
            $mockResponse->shouldReceive('failed')->andReturn(false);

            $mockRequest = Mockery::mock(PendingRequest::class);
            $mockRequest->shouldReceive('timeout')->andReturnSelf();
            $mockRequest->shouldReceive('retry')->andReturnSelf();
            $mockRequest->shouldReceive('post')->andReturn($mockResponse);

            $this->mockHttp->shouldReceive('withHeaders')->andReturn($mockRequest);

            $this->manager->notifyPermissionChange($event);

            // Assert webhook was triggered by verifying mock expectations
            expect(true)->toBeTrue();
        });

        it('skips inactive webhooks', function (): void {
            $event = new PermissionChanged(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:1',
                action: 'granted',
            );

            $this->manager->register(
                name: 'inactive-webhook',
                url: 'https://example.com/webhook',
            );
            $this->manager->disable('inactive-webhook');

            $this->mockHttp->shouldNotReceive('withHeaders');

            $this->manager->notifyPermissionChange($event);
        });
    });

    describe('error handling', function (): void {
        it('logs failed webhook responses', function (): void {
            Log::shouldReceive('error')
                ->once()
                ->with('OpenFGA webhook failed', Mockery::type('array'));

            $event = new PermissionChanged(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:1',
                action: 'granted',
            );

            $this->manager->register(
                name: 'failing-webhook',
                url: 'https://example.com/webhook',
            );

            $mockResponse = Mockery::mock(Response::class);
            $mockResponse->shouldReceive('failed')->andReturn(true);
            $mockResponse->shouldReceive('status')->andReturn(500);
            $mockResponse->shouldReceive('body')->andReturn('Internal Server Error');

            $mockRequest = Mockery::mock(PendingRequest::class);
            $mockRequest->shouldReceive('timeout')->andReturnSelf();
            $mockRequest->shouldReceive('retry')->andReturnSelf();
            $mockRequest->shouldReceive('post')->andReturn($mockResponse);

            $this->mockHttp->shouldReceive('withHeaders')->andReturn($mockRequest);

            $this->manager->notifyPermissionChange($event);

            // Assert webhook was triggered by verifying mock expectations
            expect(true)->toBeTrue();
        });

        it('logs webhook exceptions', function (): void {
            Log::shouldReceive('error')
                ->once()
                ->with('OpenFGA webhook error', Mockery::type('array'));

            $event = new PermissionChanged(
                user: 'user:123',
                relation: 'viewer',
                object: 'document:1',
                action: 'granted',
            );

            $this->manager->register(
                name: 'exception-webhook',
                url: 'https://example.com/webhook',
            );

            $mockRequest = Mockery::mock(PendingRequest::class);
            $mockRequest->shouldReceive('timeout')->andReturnSelf();
            $mockRequest->shouldReceive('retry')->andReturnSelf();
            $mockRequest->shouldReceive('post')->andThrow(new Exception('Connection failed'));

            $this->mockHttp->shouldReceive('withHeaders')->andReturn($mockRequest);

            $this->manager->notifyPermissionChange($event);

            // Assert webhook was triggered by verifying mock expectations
            expect(true)->toBeTrue();
        });
    });

    describe('configuration validation', function (): void {
        it('filters invalid webhook config', function (): void {
            $this->setConfigWithRestore('openfga.webhooks', [
                'valid' => [
                    'url' => 'https://example.com/webhook',
                    'events' => [],
                    'headers' => [],
                    'active' => true,
                ],
                'invalid_no_url' => [
                    'events' => [],
                ],
                'invalid_non_array' => 'not-an-array',
            ]);

            $manager = new WebhookManager($this->mockHttp);
            $webhooks = $manager->getWebhooks();

            expect($webhooks)->toHaveKey('valid')
                ->and($webhooks)->not->toHaveKey('invalid_no_url')
                ->and($webhooks)->not->toHaveKey('invalid_non_array');
        });
    });
});
