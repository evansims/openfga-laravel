<?php

declare(strict_types=1);

use OpenFGA\Laravel\{Events, Listeners};
use OpenFGA\Laravel\Providers\EventServiceProvider;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('EventServiceProvider', function (): void {
    beforeEach(function (): void {
        $this->provider = new EventServiceProvider($this->app);
    });

    it('extends Laravel EventServiceProvider', function (): void {
        expect($this->provider)->toBeInstanceOf(Illuminate\Foundation\Support\Providers\EventServiceProvider::class);
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(EventServiceProvider::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('defines event listener mappings', function (): void {
        $reflection = new ReflectionClass($this->provider);
        $property = $reflection->getProperty('listen');
        $property->setAccessible(true);
        $listeners = $property->getValue($this->provider);

        expect($listeners)->toBeArray();
        expect($listeners)->toHaveKey(Events\PermissionChecked::class);
        expect($listeners)->toHaveKey(Events\PermissionGranted::class);
        expect($listeners)->toHaveKey(Events\PermissionRevoked::class);
        expect($listeners)->toHaveKey(Events\BatchWriteCompleted::class);
        expect($listeners)->toHaveKey(Events\BatchWriteFailed::class);
        expect($listeners)->toHaveKey(Events\RelationExpanded::class);
        expect($listeners)->toHaveKey(Events\ObjectsListed::class);
    });

    it('maps correct listeners to events', function (): void {
        $reflection = new ReflectionClass($this->provider);
        $property = $reflection->getProperty('listen');
        $property->setAccessible(true);
        $listeners = $property->getValue($this->provider);

        // PermissionChecked should have AuditPermissionChanges and MonitorPerformance
        expect($listeners[Events\PermissionChecked::class])->toContain(
            Listeners\AuditPermissionChanges::class . '@handlePermissionChecked',
        );
        expect($listeners[Events\PermissionChecked::class])->toContain(
            Listeners\MonitorPerformance::class . '@handlePermissionChecked',
        );

        // PermissionGranted should have AuditPermissionChanges
        expect($listeners[Events\PermissionGranted::class])->toContain(
            Listeners\AuditPermissionChanges::class . '@handlePermissionGranted',
        );

        // BatchWriteCompleted should have both listeners
        expect($listeners[Events\BatchWriteCompleted::class])->toContain(
            Listeners\AuditPermissionChanges::class . '@handleBatchWriteCompleted',
        );
        expect($listeners[Events\BatchWriteCompleted::class])->toContain(
            Listeners\MonitorPerformance::class . '@handleBatchWriteCompleted',
        );
    });

    it('configures event subscribers', function (): void {
        $reflection = new ReflectionClass($this->provider);
        $property = $reflection->getProperty('subscribe');
        $property->setAccessible(true);
        $subscribers = $property->getValue($this->provider);

        expect($subscribers)->toBeArray();
        expect($subscribers)->toContain(Listeners\AuditPermissionChanges::class);
        expect($subscribers)->toContain(Listeners\MonitorPerformance::class);
    });

    it('disables event discovery', function (): void {
        expect($this->provider->shouldDiscoverEvents())->toBeFalse();
    });

    it('boots without errors', function (): void {
        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('has correct constructor parameters', function (): void {
        $reflection = new ReflectionClass(EventServiceProvider::class);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('app');
    });

    it('is marked as internal', function (): void {
        $reflection = new ReflectionClass(EventServiceProvider::class);
        $docComment = $reflection->getDocComment();

        expect($docComment)->toContain('@internal');
    });
});
