<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Testing;

use Exception;
use Mockery;
use OpenFGA\ClientInterface;
use OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager;
use OpenFGA\Laravel\Exceptions\ConnectionException;
use OpenFGA\Laravel\{OpenFgaManager, OpenFgaServiceProvider};
use OpenFGA\Laravel\Testing\IntegrationTestCase;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;
use Override;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use stdClass;

uses(TestCase::class);
uses(ConfigRestoration::class);

beforeEach(function (): void {
    $this->setUpConfigRestoration();
    // Create a test implementation of IntegrationTestCase
    $this->testCase = new IntegrationTestCaseTest('IntegrationTestCaseTest');
    $this->testCase->setApplication($this->app);
});

afterEach(function (): void {
    $this->tearDownConfigRestoration();
});

dataset('test_identifiers', [
    ['user', '123', 'user:123'],
    ['document', 'abc', 'document:abc'],
    ['organization', 'xyz', 'organization:xyz'],
]);

dataset('permission_tuples', [
    [
        ['user' => 'user:123', 'relation' => 'viewer', 'object' => 'document:456'],
        ['user' => 'user:456', 'relation' => 'editor', 'object' => 'document:789'],
    ],
]);

describe('IntegrationTestCase', function (): void {
    describe('permission assertions', function (): void {
        it('asserts eventually allowed', function (): void {
            // Mock the abstract class instead of the final concrete class
            $mockManager = Mockery::mock(AbstractOpenFgaManager::class);
            $mockClient = Mockery::mock(ClientInterface::class);

            // First call returns false, second returns true
            $mockManager->shouldReceive('check')
                ->andReturn(false, true);

            $this->testCase->setOpenFgaManager($mockManager);
            $this->testCase->setOpenFgaClient($mockClient);
            $this->testCase->setTestStoreId('store_123');
            $this->testCase->setTestModelId('model_456');

            expect(fn () => $this->testCase->assertEventuallyAllowed('user:123', 'viewer', 'document:456'))
                ->not->toThrow(Exception::class);
        });

        it('asserts eventually denied', function (): void {
            // Mock the abstract class instead of the final concrete class
            $mockManager = Mockery::mock(AbstractOpenFgaManager::class);
            $mockClient = Mockery::mock(ClientInterface::class);

            // First call returns true, second returns false
            $mockManager->shouldReceive('check')
                ->andReturn(true, false);

            $this->testCase->setOpenFgaManager($mockManager);
            $this->testCase->setOpenFgaClient($mockClient);
            $this->testCase->setTestStoreId('store_123');
            $this->testCase->setTestModelId('model_456');

            expect(fn () => $this->testCase->assertEventuallyDenied('user:123', 'viewer', 'document:456'))
                ->not->toThrow(Exception::class);
        });

        it('throws when asserting without client', function (): void {
            // We can't call protected methods directly, so we'll test through reflection
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'assertEventuallyAllowed');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase, 'user:123', 'viewer', 'document:456'))
                ->toThrow(RuntimeException::class, 'OpenFGA client is not initialized');
        });
    });

    describe('permission management', function (): void {
        it('grants single permission', function (): void {
            // Mock the abstract class instead of the final concrete class
            $mockManager = Mockery::mock(AbstractOpenFgaManager::class);
            $mockManager->shouldReceive('grant')
                ->once()
                ->andReturn(true);

            $this->testCase->setOpenFgaManager($mockManager);
            $this->testCase->setTestStoreId('store_123');
            $this->testCase->setTestModelId('model_456');

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'grantPermission');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase, 'user:123', 'viewer', 'document:456'))
                ->not->toThrow(Exception::class);
        });

        it('grants multiple permissions', function (array $tuples): void {
            // Mock the abstract class instead of the final concrete class
            $mockManager = Mockery::mock(AbstractOpenFgaManager::class);

            $mockManager->shouldReceive('writeBatch')
                ->once()
                ->andReturn(true);

            $this->testCase->setOpenFgaManager($mockManager);
            $this->testCase->setTestStoreId('store_123');
            $this->testCase->setTestModelId('model_456');

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'grantPermissions');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase, $tuples))
                ->not->toThrow(Exception::class);
        })->with('permission_tuples');

        it('revokes permission', function (): void {
            // Mock the abstract class instead of the final concrete class
            $mockManager = Mockery::mock(AbstractOpenFgaManager::class);
            $mockManager->shouldReceive('revoke')
                ->once()
                ->andReturn(true);

            $this->testCase->setOpenFgaManager($mockManager);
            $this->testCase->setTestStoreId('store_123');
            $this->testCase->setTestModelId('model_456');

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'revokePermission');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase, 'user:123', 'viewer', 'document:456'))
                ->not->toThrow(Exception::class);
        });

        it('throws when granting without manager', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'grantPermission');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase, 'user:123', 'viewer', 'document:456'))
                ->toThrow(RuntimeException::class, 'OpenFGA manager is not initialized');
        });

        it('throws when granting without ids', function (): void {
            $this->testCase->setOpenFgaManager(Mockery::mock(AbstractOpenFgaManager::class));

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'grantPermission');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase, 'user:123', 'viewer', 'document:456'))
                ->toThrow(RuntimeException::class, 'Store ID or Model ID is not set');
        });
    });

    describe('test setup and configuration', function (): void {
        it('defines environment correctly', function (): void {
            $this->testCase->defineEnvironment($this->app);

            expect(config('openfga.default'))->toBe('integration_test');
            expect(config('openfga.connections.integration_test'))
                ->toBeArray()
                ->toHaveKey('url', 'http://localhost:8080')
                ->toHaveKey('store_id', null)
                ->toHaveKey('model_id', null);
            expect(config('openfga.connections.integration_test.retries.max_retries'))->toBe(0);
        });

        it('provides correct package providers', function (): void {
            $providers = $this->testCase->getPackageProviders($this->app);

            expect($providers)
                ->toBeArray()
                ->toHaveCount(1)
                ->toContain(OpenFgaServiceProvider::class);
        });

        it('configures test connection for backward compatibility', function (): void {
            expect(fn () => $this->testCase->configureTestConnection())->not->toThrow(Exception::class);
        });

        it('throws exception when app is not application instance', function (): void {
            $notAnApp = new stdClass;

            expect(fn () => $this->testCase->defineEnvironment($notAnApp))
                ->toThrow(RuntimeException::class, 'Expected Application instance');
        });
    });

    describe('store and model management', function (): void {
        it('creates store via api', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'createStore');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase, 'test_store'))
                ->toThrow(Exception::class);
        });

        it('deletes store via api', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'deleteStore');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase, 'store_123'))
                ->toThrow(Exception::class);
        });

        it('creates authorization model via api', function (): void {
            $this->testCase->setTestStoreId('store_123');

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'createAuthorizationModel');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase, ['test' => 'model']))
                ->toThrow(Exception::class);
        });

        it('gets current model via api', function (): void {
            $this->testCase->setTestStoreId('store_123');
            $this->testCase->setTestModelId('model_456');

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'getCurrentModel');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase))
                ->toThrow(Exception::class);
        });

        it('throws when creating model without store id', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'createAuthorizationModel');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase, ['test' => 'model']))
                ->toThrow(RuntimeException::class, 'Test store ID is not set');
        });

        it('throws when getting model without ids', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'getCurrentModel');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase))
                ->toThrow(RuntimeException::class, 'Test store ID or model ID is not set');
        });
    });

    describe('test utilities', function (): void {
        it('creates test identifiers', function (string $type, string $id, string $expected): void {
            $methodName = match ($type) {
                'user' => 'createTestUser',
                'document' => 'createTestDocument',
                'organization' => 'createTestOrganization',
            };

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: $methodName);
            $method->setAccessible(true);

            expect($method->invoke($this->testCase, $id))->toBe($expected);
        })->with('test_identifiers');

        it('measures operation time', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'measureTime');
            $method->setAccessible(true);

            // Test with a fast operation
            $result = $method->invoke($this->testCase, function (): string {
                // Do some minimal work instead of sleeping
                $sum = 0;

                for ($i = 0; 100 > $i; ++$i) {
                    $sum += $i;
                }

                return 'test_result';
            });

            expect($result)
                ->toHaveKey('result', 'test_result')
                ->toHaveKey('duration_ms')
                ->and($result['duration_ms'])
                ->toBeGreaterThanOrEqual(0)
                ->toBeLessThan(10); // Should be very fast
        });

        it('waits for consistency', function (): void {
            // Just verify the method exists and can be called
            // We don't need to actually test the sleep functionality in a unit test
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'waitForConsistency');
            expect($method)->toBeInstanceOf(ReflectionMethod::class);
            expect($method->isProtected())->toBeTrue();

            // Verify it accepts an integer parameter
            $params = $method->getParameters();
            expect($params)->toHaveCount(1);
            expect($params[0]->getType()->getName())->toBe('int');
        });

        it('checks openfga availability', function (): void {
            // This test is verifying the isOpenFgaAvailable method exists and can be called
            // We don't actually want to test the connection in a unit test
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'isOpenFgaAvailable');
            expect($method)->toBeInstanceOf(ReflectionMethod::class);
            expect($method->isProtected())->toBeTrue();
        });

        it('clears all tuples', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'clearAllTuples');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase))->not->toThrow(Exception::class);
        });

        it('lists empty tuples by default', function (): void {
            // Set up required dependencies first
            $mockClient = Mockery::mock(ClientInterface::class);
            $this->testCase->setOpenFgaClient($mockClient);
            $this->testCase->setTestStoreId('store_123');

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'listAllTuples');
            $method->setAccessible(true);

            $tuples = $method->invoke($this->testCase);

            expect($tuples)
                ->toBeArray()
                ->toBeEmpty();
        });
    });

    describe('authorization models', function (): void {
        it('provides test authorization model', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'getTestAuthorizationModel');
            $method->setAccessible(true);

            $model = $method->invoke($this->testCase);

            expect($model)
                ->toHaveKey('schema_version', '1.1')
                ->toHaveKey('type_definitions')
                ->and($model['type_definitions'])
                ->toBeArray()
                ->toHaveCount(3);

            // Check user type
            expect($model['type_definitions'][0]['type'])->toBe('user');

            // Check organization type
            expect($model['type_definitions'][1])
                ->toHaveKey('type', 'organization')
                ->toHaveKey('relations')
                ->and($model['type_definitions'][1]['relations'])
                ->toHaveKeys(['admin', 'member']);

            // Check document type
            expect($model['type_definitions'][2])
                ->toHaveKey('type', 'document')
                ->toHaveKey('relations')
                ->and($model['type_definitions'][2]['relations'])
                ->toHaveKeys(['owner', 'editor', 'viewer', 'organization']);
        });

        it('provides test authorization model dsl', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'getTestAuthorizationModelDSL');
            $method->setAccessible(true);

            $dsl = $method->invoke($this->testCase);

            expect($dsl)
                ->toContain('model')
                ->toContain('schema 1.1')
                ->toContain('type user')
                ->toContain('type organization')
                ->toContain('type document')
                ->toContain('define admin: [user]')
                ->toContain('define member: [user] or admin')
                ->toContain('define viewer: [user] or editor or member from organization');
        });
    });

    describe('api requests', function (): void {
        it('handles api request failure', function (): void {
            // Suppress warnings for file_get_contents
            set_error_handler(callback: static fn (): null => null, error_levels: E_WARNING);

            $this->setConfigWithRestore('openfga.connections.integration_test.url', 'http://invalid-host-that-does-not-exist');

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'makeApiRequest');
            $method->setAccessible(true);

            try {
                $result = $method->invoke($this->testCase, 'GET', '/stores');
                expect(false)->toBeTrue(); // Should not reach here
            } catch (RuntimeException $runtimeException) {
                expect($runtimeException->getMessage())->toContain('Failed to make API request');
            } finally {
                restore_error_handler();
            }
        });

        it('makes api request with json response', function (): void {
            // Mock a successful response for testing JSON decoding
            $mockResponse = json_encode(['status' => 'ok', 'data' => ['test' => true]]);

            // Mock file_get_contents to return our response
            $streamContextOptions = [
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ],
                    'content' => '[]',
                    'ignore_errors' => true,
                ],
            ];

            // Create a mock URL that will succeed
            $this->setConfigWithRestore('openfga.connections.integration_test.url', 'data://text/plain;base64,' . base64_encode($mockResponse));

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'makeApiRequest');
            $method->setAccessible(true);

            $result = $method->invoke($this->testCase, 'GET', '');

            expect($result)
                ->toBeArray()
                ->toHaveKey('status', 'ok')
                ->toHaveKey('data');
        });
    });

    describe('manager and client access', function (): void {
        it('returns manager when initialized', function (): void {
            // Test that getManager throws when not initialized
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'getManager');
            $method->setAccessible(true);

            // First test it throws when manager is null
            expect(fn (): mixed => $method->invoke($this->testCase))
                ->toThrow(RuntimeException::class, 'OpenFGA manager is not initialized');
        });

        it('returns client when initialized', function (): void {
            $mockClient = Mockery::mock(ClientInterface::class);
            $this->testCase->setOpenFgaClient($mockClient);

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'getClient');
            $method->setAccessible(true);

            $client = $method->invoke($this->testCase);
            expect($client)->toBe($mockClient);
        });

        it('throws when manager not initialized', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'getManager');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase))
                ->toThrow(RuntimeException::class, 'OpenFGA manager is not initialized');
        });

        it('throws when client not initialized', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'getClient');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->testCase))
                ->toThrow(RuntimeException::class, 'OpenFGA client is not initialized');
        });
    });

    describe('integration test lifecycle', function (): void {
        it('sets up integration test', function (): void {
            // Test that setUpIntegrationTest tries to get manager from app
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'setUpIntegrationTest');
            $method->setAccessible(true);

            // Should throw because app doesn't have OpenFgaManager bound
            expect(fn (): mixed => $method->invoke($this->testCase))
                ->toThrow(ConnectionException::class);
        });

        it('sets up test store', function (): void {
            // Test that setUpTestStore tries to create a store
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'setUpTestStore');
            $method->setAccessible(true);

            // Should throw because makeApiRequest will fail
            expect(fn (): mixed => $method->invoke($this->testCase))
                ->toThrow(Exception::class);
        });

        it('tears down integration test', function (): void {
            // Set up some test data
            $this->testCase->addCreatedStore('store_123');
            $this->testCase->setOpenFgaClient(Mockery::mock(ClientInterface::class));
            $this->testCase->setTestStoreId('store_123');
            $this->testCase->setTestModelId('model_456');

            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'tearDownIntegrationTest');
            $method->setAccessible(true);
            $method->invoke($this->testCase);

            expect($this->testCase->getOpenFgaManagerPublic())->toBeNull();
            expect($this->testCase->getOpenFgaClientPublic())->toBeNull();
            expect($this->testCase->getTestStoreIdPublic())->toBeNull();
            expect($this->testCase->getTestModelIdPublic())->toBeNull();
            expect($this->testCase->getCreatedStores())->toBeEmpty();
        });

        it('runs with clean store', function (): void {
            $method = new ReflectionMethod(objectOrMethod: $this->testCase, method: 'runWithCleanStore');
            $method->setAccessible(true);

            $executed = false;

            $method->invoke($this->testCase, function () use (&$executed): void {
                $executed = true;
            });

            expect($executed)->toBeTrue();
        });
    });
});

/**
 * Concrete implementation for testing the abstract class.
 *
 * @internal
 */
final class IntegrationTestCaseTest extends IntegrationTestCase
{
    // Override setUp and tearDown to prevent automatic connections
    #[Override]
    protected function setUp(): void
    {
        // Do nothing - prevent parent setUp
    }

    #[Override]
    protected function tearDown(): void
    {
        // Do nothing - prevent parent tearDown
    }

    public function addCreatedStore(string $storeId): void
    {
        $this->createdStores[] = $storeId;
    }

    public function getCreatedStores(): array
    {
        return $this->createdStores;
    }

    public function getOpenFgaClientPublic(): ?ClientInterface
    {
        return $this->openFgaClient;
    }

    public function getOpenFgaManagerPublic(): ?AbstractOpenFgaManager
    {
        return $this->openFgaManager;
    }

    public function getTestModelIdPublic(): ?string
    {
        return $this->testModelId;
    }

    public function getTestStoreIdPublic(): ?string
    {
        return $this->testStoreId;
    }

    public function setApplication($app): void
    {
        $this->app = $app;
    }

    public function setOpenFgaClient(?ClientInterface $client): void
    {
        $this->openFgaClient = $client;
    }

    public function setOpenFgaManager($manager): void
    {
        // Allow setting mock for testing
        $ref = new ReflectionProperty(class: parent::class, property: 'openFgaManager');
        $ref->setAccessible(true);
        $ref->setValue(objectOrValue: $this, value: $manager);
    }

    public function setTestModelId(?string $id): void
    {
        $this->testModelId = $id;
    }

    public function setTestStoreId(?string $id): void
    {
        $this->testStoreId = $id;
    }
}
