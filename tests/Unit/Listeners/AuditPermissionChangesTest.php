<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Mockery;
use OpenFGA\Laravel\Events\{BatchWriteCompleted, BatchWriteFailed, PermissionChecked, PermissionGranted, PermissionRevoked};
use OpenFGA\Laravel\Listeners\AuditPermissionChanges;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;
use Psr\Log\LoggerInterface;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('AuditPermissionChanges', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->listener = new AuditPermissionChanges;

        // Mock the logger
        $this->mockLogger = Mockery::mock(LoggerInterface::class);

        // Enable logging by default
        $this->setConfigWithRestore('openfga.logging.enabled', true);
        $this->setConfigWithRestore('openfga.logging.operations', ['check', 'grant', 'revoke', 'batch']);
    });

    afterEach(function (): void {
        Mockery::close();
        Log::clearResolvedInstances();
        $this->tearDownConfigRestoration();
    });

    function setupLogMock(): void
    {
        Log::shouldReceive('channel')
            ->with(Mockery::any())
            ->andReturn(test()->mockLogger);
    }

    it('handles invalid config values gracefully', function (): void {
        setupLogMock();

        // Invalid enabled config - defaults to true
        $this->setConfigWithRestore('openfga.logging.enabled', 'not-a-boolean');

        $this->mockLogger->shouldReceive('info')->once();

        $event = new PermissionChecked(
            user: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            allowed: true,
            connection: 'default',
            duration: 0.025,
            cached: false,
        );

        $this->listener->handlePermissionChecked($event);

        // Invalid operations config - logs all operations
        $this->setConfigWithRestore('openfga.logging.enabled', true);
        $this->setConfigWithRestore('openfga.logging.operations', 'not-an-array');

        $this->mockLogger->shouldReceive('info')->once();

        $this->listener->handlePermissionChecked($event);
    });

    it('implements ShouldQueue interface', function (): void {
        expect($this->listener)->toBeInstanceOf(ShouldQueue::class);
    });

    it('logs batch write completed events', function (): void {
        setupLogMock();

        $writes = [];

        for ($i = 0; 50 > $i; $i++) {
            $writes[] = ['user' => 'user:' . $i, 'relation' => 'viewer', 'object' => 'document:' . $i];
        }

        $deletes = [];

        for ($i = 0; 10 > $i; $i++) {
            $deletes[] = ['user' => 'user:' . $i, 'relation' => 'editor', 'object' => 'document:' . $i];
        }

        $event = new BatchWriteCompleted(
            writes: $writes,
            deletes: $deletes,
            connection: 'default',
            duration: 0.150,
            options: ['batch_id' => 'batch_001'],
        );

        $this->mockLogger->shouldReceive('info')
            ->once()
            ->with(
                'OpenFGA batch write completed',
                [
                    'writes' => 50,
                    'deletes' => 10,
                    'total' => 60,
                    'duration' => 0.150,
                    'connection' => 'default',
                ],
            );

        $this->listener->handleBatchWriteCompleted($event);
    });

    it('logs batch write failed events', function (): void {
        setupLogMock();

        $writes = [];

        for ($i = 0; 100 > $i; $i++) {
            $writes[] = ['user' => 'user:' . $i, 'relation' => 'viewer', 'object' => 'document:' . $i];
        }

        $deletes = [];

        for ($i = 0; 20 > $i; $i++) {
            $deletes[] = ['user' => 'user:' . $i, 'relation' => 'editor', 'object' => 'document:' . $i];
        }

        $exception = new Exception('Connection timeout');
        $event = new BatchWriteFailed(
            writes: $writes,
            deletes: $deletes,
            connection: 'default',
            exception: $exception,
            options: ['batch_id' => 'batch_002'],
        );

        $this->mockLogger->shouldReceive('error')
            ->once()
            ->with(
                'OpenFGA batch write failed',
                Mockery::on(fn ($context) => 100 === $context['writes']
                           && 20 === $context['deletes']
                           && 120 === $context['total']
                           && 'Connection timeout' === $context['error']
                           && isset($context['trace'])
                           && 'default' === $context['connection']),
            );

        $this->listener->handleBatchWriteFailed($event);
    });

    it('logs permission checked events', function (): void {
        setupLogMock();

        $event = new PermissionChecked(
            user: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            allowed: true,
            connection: 'default',
            duration: 0.025,
            cached: false,
            context: ['request_id' => 'abc123'],
        );

        $this->mockLogger->shouldReceive('info')
            ->once()
            ->with(
                'OpenFGA permission check: user:123#viewer@document:456 = allowed',
                [
                    'user' => 'user:123',
                    'relation' => 'viewer',
                    'object' => 'document:456',
                    'allowed' => true,
                    'connection' => 'default',
                    'duration_ms' => 25.0,
                    'cached' => false,
                    'request_id' => 'abc123',
                ],
            );

        $this->listener->handlePermissionChecked($event);
    });

    it('logs permission granted events', function (): void {
        setupLogMock();

        $event = new PermissionGranted(
            user: 'user:123',
            relation: 'editor',
            object: 'document:789',
            connection: 'secondary',
            duration: 0.050,
            context: ['ip' => '192.168.1.1'],
        );

        $this->mockLogger->shouldReceive('info')
            ->once()
            ->with(
                'OpenFGA permission granted: Granted: user:123#editor@document:789',
                [
                    'user' => 'user:123',
                    'relation' => 'editor',
                    'object' => 'document:789',
                    'connection' => 'secondary',
                    'duration_ms' => 50.0,
                    'ip' => '192.168.1.1',
                ],
            );

        $this->listener->handlePermissionGranted($event);
    });

    it('logs permission revoked events', function (): void {
        setupLogMock();

        $event = new PermissionRevoked(
            user: 'user:456',
            relation: 'admin',
            object: 'organization:123',
            connection: 'default',
            duration: 0.030,
            context: ['reason' => 'User left organization'],
        );

        $this->mockLogger->shouldReceive('info')
            ->once()
            ->with(
                'OpenFGA permission revoked: Revoked: user:456#admin@organization:123',
                [
                    'user' => 'user:456',
                    'relation' => 'admin',
                    'object' => 'organization:123',
                    'connection' => 'default',
                    'duration_ms' => 30.0,
                    'reason' => 'User left organization',
                ],
            );

        $this->listener->handlePermissionRevoked($event);
    });

    it('respects logging disabled setting', function (): void {
        $this->setConfigWithRestore('openfga.logging.enabled', false);

        $this->mockLogger->shouldNotReceive('info');
        $this->mockLogger->shouldNotReceive('error');

        $event = new PermissionChecked(
            user: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            allowed: true,
            connection: 'default',
            duration: 0.025,
            cached: false,
        );

        $this->listener->handlePermissionChecked($event);
    });

    it('respects operation filtering', function (): void {
        setupLogMock();

        $this->setConfigWithRestore('openfga.logging.operations', ['grant', 'revoke']);

        // Should not log check operations
        $this->mockLogger->shouldNotReceive('info');

        $checkEvent = new PermissionChecked(
            user: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            allowed: true,
            connection: 'default',
            duration: 0.025,
            cached: false,
        );

        $this->listener->handlePermissionChecked($checkEvent);

        // Should log grant operations
        $this->mockLogger->shouldReceive('info')->once();

        $grantEvent = new PermissionGranted(
            user: 'user:123',
            relation: 'editor',
            object: 'document:789',
            connection: 'default',
            duration: 0.050,
        );

        $this->listener->handlePermissionGranted($grantEvent);
    });

    it('subscribes to correct events', function (): void {
        $dispatcher = Mockery::mock('Illuminate\Events\Dispatcher');

        $subscriptions = $this->listener->subscribe($dispatcher);

        expect($subscriptions)->toHaveKey(PermissionChecked::class, 'handlePermissionChecked');
        expect($subscriptions)->toHaveKey(PermissionGranted::class, 'handlePermissionGranted');
        expect($subscriptions)->toHaveKey(PermissionRevoked::class, 'handlePermissionRevoked');
        expect($subscriptions)->toHaveKey(BatchWriteCompleted::class, 'handleBatchWriteCompleted');
        expect($subscriptions)->toHaveKey(BatchWriteFailed::class, 'handleBatchWriteFailed');
    });

    it('uses custom log channel when configured', function (): void {
        $this->setConfigWithRestore('openfga.logging.channel', 'custom-channel');

        Log::shouldReceive('channel')
            ->once()
            ->with('custom-channel')
            ->andReturn($this->mockLogger);

        $this->mockLogger->shouldReceive('info')->once();

        $event = new PermissionChecked(
            user: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            allowed: true,
            connection: 'default',
            duration: 0.025,
            cached: false,
        );

        $this->listener->handlePermissionChecked($event);
    });

    it('uses default log channel when not configured', function (): void {
        $this->setConfigWithRestore('openfga.logging.channel', null);
        $this->setConfigWithRestore('logging.default', 'daily');

        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturn($this->mockLogger);

        $this->mockLogger->shouldReceive('info')->once();

        $event = new PermissionChecked(
            user: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            allowed: true,
            connection: 'default',
            duration: 0.025,
            cached: false,
        );

        $this->listener->handlePermissionChecked($event);
    });

    it('uses interacts with queue trait', function (): void {
        $traits = class_uses($this->listener);

        expect($traits)->toContain(InteractsWithQueue::class);
    });
});
