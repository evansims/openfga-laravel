<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Facades;

use Illuminate\Support\Facades\Facade;
use OpenFGA\Laravel\Facades\OpenFga;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Tests\TestCase;
use ReflectionClass;

uses(TestCase::class);

describe('OpenFga Facade', function (): void {
    beforeEach(function (): void {
        // Clear any facade instances
        Facade::clearResolvedInstances();
    });

    it('extends Laravel Facade', function (): void {
        expect(OpenFga::class)->toExtend(Facade::class);
    });

    it('returns the correct facade accessor', function (): void {
        $reflection = new ReflectionClass(OpenFga::class);
        $method = $reflection->getMethod('getFacadeAccessor');
        $method->setAccessible(true);

        expect($method->invoke(null))->toBe('openfga.manager');
    });

    it('resolves to OpenFgaManager', function (): void {
        $manager = OpenFga::getFacadeRoot();
        expect($manager)->toBeInstanceOf(OpenFgaManager::class);
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(OpenFga::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    describe('Facade Methods', function (): void {
        it('has proper method annotations', function (): void {
            $reflection = new ReflectionClass(OpenFga::class);
            $docComment = $reflection->getDocComment();

            expect($docComment)->toContain('@method static')
                ->and($docComment)->toContain('check(')
                ->and($docComment)->toContain('batchCheck(')
                ->and($docComment)->toContain('expand(')
                ->and($docComment)->toContain('listObjects(')
                ->and($docComment)->toContain('listRelations(')
                ->and($docComment)->toContain('listUsers(')
                ->and($docComment)->toContain('write(')
                ->and($docComment)->toContain('writeBatch(')
                ->and($docComment)->toContain('writeTuples(')
                ->and($docComment)->toContain('deleteTuples(')
                ->and($docComment)->toContain('readTuples(')
                ->and($docComment)->toContain('readChanges(')
                ->and($docComment)->toContain('createStore(')
                ->and($docComment)->toContain('getStore(')
                ->and($docComment)->toContain('deleteStore(')
                ->and($docComment)->toContain('listStores(')
                ->and($docComment)->toContain('writeAuthorizationModel(')
                ->and($docComment)->toContain('readAuthorizationModel(')
                ->and($docComment)->toContain('readAuthorizationModels(')
                ->and($docComment)->toContain('readLatestAuthorizationModel(')
                ->and($docComment)->toContain('writeAssertions(')
                ->and($docComment)->toContain('readAssertions(')
                ->and($docComment)->toContain('dsl(');
        });

        it('has @see annotation pointing to Client', function (): void {
            $reflection = new ReflectionClass(OpenFga::class);
            $docComment = $reflection->getDocComment();

            expect($docComment)->toContain('@see Client');
        });

        it('has @api annotation', function (): void {
            $reflection = new ReflectionClass(OpenFga::class);
            $docComment = $reflection->getDocComment();

            expect($docComment)->toContain('@api');
        });
    });

    describe('Integration Tests', function (): void {
        it('can call methods on the resolved manager', function (): void {
            // Since we can't mock the final class, we'll just test that the facade
            // properly resolves and we can access the manager instance
            $manager = OpenFga::getFacadeRoot();

            expect($manager)->toBeInstanceOf(OpenFgaManager::class)
                ->and(method_exists($manager, 'check'))->toBeTrue()
                ->and(method_exists($manager, 'batchCheck'))->toBeTrue()
                ->and(method_exists($manager, 'expand'))->toBeTrue()
                ->and(method_exists($manager, 'listObjects'))->toBeTrue()
                ->and(method_exists($manager, 'listRelations'))->toBeTrue()
                ->and(method_exists($manager, 'listUsers'))->toBeTrue()
                ->and(method_exists($manager, 'write'))->toBeTrue()
                ->and(method_exists($manager, 'writeBatch'))->toBeTrue()
                ->and(method_exists($manager, 'grant'))->toBeTrue()
                ->and(method_exists($manager, 'revoke'))->toBeTrue()
                ->and(method_exists($manager, 'query'))->toBeTrue()
                ->and(method_exists($manager, 'connection'))->toBeTrue()
                ->and(method_exists($manager, 'disconnect'))->toBeTrue()
                ->and(method_exists($manager, 'disconnectAll'))->toBeTrue()
                ->and(method_exists($manager, 'healthCheck'))->toBeTrue()
                ->and(method_exists($manager, 'healthCheckAll'))->toBeTrue()
                ->and(method_exists($manager, 'getConnections'))->toBeTrue()
                ->and(method_exists($manager, 'getDefaultConnection'))->toBeTrue()
                ->and(method_exists($manager, 'setDefaultConnection'))->toBeTrue()
                ->and(method_exists($manager, 'throwExceptions'))->toBeTrue()
                ->and(method_exists($manager, 'updateConfig'))->toBeTrue()
                ->and(method_exists($manager, '__call'))->toBeTrue();
        });

        it('maintains singleton instance through facade', function (): void {
            $instance1 = OpenFga::getFacadeRoot();
            $instance2 = OpenFga::getFacadeRoot();

            expect($instance1)->toBe($instance2);
        });

        it('can be resolved from container using facade accessor', function (): void {
            $fromContainer = $this->app->make('openfga.manager');
            $fromFacade = OpenFga::getFacadeRoot();

            expect($fromContainer)->toBe($fromFacade);
        });
    });

    describe('Facade Behavior', function (): void {
        it('supports facade clearResolvedInstances', function (): void {
            // Get initial instance
            $instance1 = OpenFga::getFacadeRoot();

            // Clear resolved instances
            Facade::clearResolvedInstances();

            // Get new instance (should be the same singleton from container)
            $instance2 = OpenFga::getFacadeRoot();

            // They should still be the same because the container maintains the singleton
            expect($instance1)->toBe($instance2);
        });

        it('supports facade clearResolvedInstance', function (): void {
            // Get initial instance
            $instance1 = OpenFga::getFacadeRoot();

            // Clear specific facade instance
            OpenFga::clearResolvedInstance('openfga.manager');

            // Get new instance (should be the same singleton from container)
            $instance2 = OpenFga::getFacadeRoot();

            // They should still be the same because the container maintains the singleton
            expect($instance1)->toBe($instance2);
        });

        it('can get facade accessor through reflection', function (): void {
            $reflection = new ReflectionClass(OpenFga::class);
            $method = $reflection->getMethod('getFacadeAccessor');
            $method->setAccessible(true);

            $accessor = $method->invoke(null);

            expect($accessor)->toBe('openfga.manager')
                ->and($this->app->bound($accessor))->toBeTrue();
        });
    });

    describe('Documentation Coverage', function (): void {
        it('documents all primary OpenFGA operations', function (): void {
            $reflection = new ReflectionClass(OpenFga::class);
            $docComment = $reflection->getDocComment();

            // Core authorization methods
            expect($docComment)->toContain('check(string $user, string $relation, string $object')
                ->and($docComment)->toContain('batchCheck(array')
                ->and($docComment)->toContain('expand(string $relation, string $object');

            // List operations
            expect($docComment)->toContain('listObjects(string $user, string $relation, string $type')
                ->and($docComment)->toContain('listRelations(string $user, string $object')
                ->and($docComment)->toContain('listUsers(string $object, string $relation');

            // Write operations
            expect($docComment)->toContain('write(array')
                ->and($docComment)->toContain('writeBatch(array')
                ->and($docComment)->toContain('writeTuples(array')
                ->and($docComment)->toContain('deleteTuples(array');

            // Read operations
            expect($docComment)->toContain('readTuples(?string $user')
                ->and($docComment)->toContain('readChanges(?string $type');

            // Store operations
            expect($docComment)->toContain('createStore(string $name')
                ->and($docComment)->toContain('getStore(?string $storeId')
                ->and($docComment)->toContain('deleteStore(?string $storeId')
                ->and($docComment)->toContain('listStores(?int $pageSize');

            // Model operations
            expect($docComment)->toContain('writeAuthorizationModel(array')
                ->and($docComment)->toContain('readAuthorizationModel(?string $authorizationModelId')
                ->and($docComment)->toContain('readAuthorizationModels(?int $pageSize')
                ->and($docComment)->toContain('readLatestAuthorizationModel(?string $storeId');

            // Assertion operations
            expect($docComment)->toContain('writeAssertions(array')
                ->and($docComment)->toContain('readAssertions(?string $authorizationModelId');

            // DSL operation
            expect($docComment)->toContain('dsl(string $dsl');
        });

        it('documents return types correctly', function (): void {
            $reflection = new ReflectionClass(OpenFga::class);
            $docComment = $reflection->getDocComment();

            // Most methods return Success|Failure
            expect($docComment)->toContain('\\OpenFGA\\Results\\Success|\\OpenFGA\\Results\\Failure');

            // writeBatch returns bool
            expect($docComment)->toContain('@method static bool')
                ->and($docComment)->toContain('writeBatch(');
        });
    });
});
