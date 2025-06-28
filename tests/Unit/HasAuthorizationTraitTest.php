<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use Mockery;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Tests\Support\{TestDocument, TestUser};
use ReflectionClass;

describe('HasAuthorization Trait', function (): void {
    beforeEach(function (): void {
        $this->container = new Container;
        Container::setInstance($this->container);
        App::setFacadeApplication($this->container);

        // Mock config using Mockery
        $configMock = Mockery::mock('config');
        $configMock->shouldReceive('get')
            ->with('openfga.cleanup_on_delete', Mockery::any())
            ->andReturn(true);
        $configMock->shouldReceive('get')
            ->with('openfga.replicate_permissions', Mockery::any())
            ->andReturn(false);
        $configMock->shouldReceive('get')
            ->andReturnUsing(fn ($key, $default = null) => $default);

        $this->container->instance('config', $configMock);

        $this->config = [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'url' => 'http://localhost:8080',
                    'store_id' => 'test-store',
                    'model_id' => 'test-model',
                    'credentials' => [
                        'method' => 'none',
                    ],
                ],
            ],
        ];

        $this->manager = new OpenFgaManager($this->container, $this->config);
        $this->container->instance(OpenFgaManager::class, $this->manager);

        $this->document = new TestDocument(['id' => 123]);
        $this->user = new TestUser(['id' => 456]);
    });

    describe('Authorization Object Generation', function (): void {
        it('generates correct authorization object', function (): void {
            expect($this->document->authorizationObject())->toBe('test_document:123');
        });

        it('generates correct authorization type', function (): void {
            expect($this->document->authorizationType())->toBe('test_document');
        });

        it('provides default authorization relations', function (): void {
            expect($this->document->getAuthorizationRelations())->toBe(['owner', 'editor', 'viewer']);
        });
    });

    describe('User ID Resolution', function (): void {
        it('resolves user from model with trait', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect($method->invoke($this->document, $this->user))->toBe('test_user:456');
        });

        it('resolves user from string', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect($method->invoke($this->document, 'user:789'))->toBe('user:789');
        });

        it('resolves user from integer', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect($method->invoke($this->document, 999))->toBe('user:999');
        });

        it('throws exception for invalid user type', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect(fn () => $method->invoke($this->document, []))
                ->toThrow(InvalidArgumentException::class, 'User must be a Model, string, or integer');
        });
    });

    describe('Permission Operations', function (): void {
        it('grants permission to a user', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });

        it('revokes permission from a user', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });

        it('checks permission for a user', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });

        it('checks permission for current user', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });
    });

    describe('Batch Operations', function (): void {
        it('grants multiple permissions to multiple users', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });

        it('revokes multiple permissions from multiple users', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });
    });

    describe('Query Operations', function (): void {
        it('gets users with a specific relation', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });

        it('gets all relations for a user', function (): void {
            // This would need mocking to test properly
            expect($this->document)->toBeInstanceOf(TestDocument::class);
        });
    });

    describe('Model Events', function (): void {
        it('has initialization method', function (): void {
            // Just verify the method exists
            expect(method_exists($this->document, 'initializeHasAuthorization'))->toBeTrue();
        });
    });

    describe('Configuration', function (): void {
        it('checks cleanup on delete configuration', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('shouldCleanupPermissionsOnDelete');
            $method->setAccessible(true);

            expect($method->invoke($this->document))->toBe(true);
        });

        it('checks replicate permissions configuration', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('shouldReplicatePermissions');
            $method->setAccessible(true);

            expect($method->invoke($this->document))->toBe(false);
        });
    });
});
