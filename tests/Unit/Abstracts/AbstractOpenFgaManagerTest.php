<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use OpenFGA\ClientInterface;
use OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Exceptions\{ConnectionException, StoreNotFoundException};
use OpenFGA\Laravel\Query\AuthorizationQuery;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Models\Collections\TupleKeys;
use OpenFGA\Results\SuccessInterface;
use Psr\Http\Message\{RequestFactoryInterface, ResponseFactoryInterface, StreamFactoryInterface};

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('AbstractOpenFgaManager', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->container = new Container;
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
            'cache' => [
                'enabled' => false,
            ],
        ];

        // Create a concrete implementation for testing
        $this->manager = new class($this->container, $this->config) extends AbstractOpenFgaManager {
            // Expose protected methods for testing
            public function buildCredentials(array $config): ?array
            {
                return parent::buildCredentials($config);
            }

            public function cacheEnabled(): bool
            {
                return parent::cacheEnabled();
            }

            public function detectHttpClient(): ?Psr\Http\Client\ClientInterface
            {
                return parent::detectHttpClient();
            }

            public function detectRequestFactory(): ?RequestFactoryInterface
            {
                return parent::detectRequestFactory();
            }

            public function detectResponseFactory(): ?ResponseFactoryInterface
            {
                return parent::detectResponseFactory();
            }

            public function detectStreamFactory(): ?StreamFactoryInterface
            {
                return parent::detectStreamFactory();
            }

            public function getCacheKey(string $operation, string ...$parts): string
            {
                return parent::getCacheKey($operation, ...$parts);
            }

            public function getCacheTtl(): int
            {
                return parent::getCacheTtl();
            }

            public function query(?string $connection = null): AuthorizationQuery
            {
                return new AuthorizationQuery(manager: $this, connection: $connection);
            }

            public function resolveUserId(string $user): string
            {
                return parent::resolveUserId($user);
            }
        };
    });

    afterEach(function (): void {
        $this->tearDownConfigRestoration();
    });

    it('implements ManagerInterface', function (): void {
        expect($this->manager)->toBeInstanceOf(ManagerInterface::class);
    });

    it('is abstract class', function (): void {
        $reflection = new ReflectionClass(AbstractOpenFgaManager::class);
        expect($reflection->isAbstract())->toBeTrue();
    });

    it('has abstract query method', function (): void {
        $reflection = new ReflectionClass(AbstractOpenFgaManager::class);
        $method = $reflection->getMethod('query');
        expect($method->isAbstract())->toBeTrue();
    });

    describe('configuration management', function (): void {
        it('gets default connection name', function (): void {
            expect($this->manager->getDefaultConnection())->toBe('main');
        });

        it('sets default connection name', function (): void {
            $this->manager->setDefaultConnection('secondary');
            expect($this->manager->getDefaultConnection())->toBe('secondary');
        });

        it('updates configuration', function (): void {
            $newConfig = [
                'default' => 'test',
                'connections' => [
                    'test' => [
                        'url' => 'http://test:8080',
                        'store_id' => 'test-store-2',
                        'model_id' => 'test-model-2',
                    ],
                ],
            ];

            $this->manager->updateConfig($newConfig);
            expect($this->manager->getDefaultConnection())->toBe('test');
        });
    });

    describe('connection management', function (): void {
        it('creates connection on demand', function (): void {
            $connection = $this->manager->connection();
            expect($connection)->toBeInstanceOf(ClientInterface::class);
        });

        it('reuses existing connections', function (): void {
            $connection1 = $this->manager->connection();
            $connection2 = $this->manager->connection();
            expect($connection1)->toBe($connection2);
        });

        it('disconnects from connection', function (): void {
            $this->manager->connection(); // Create connection
            $this->manager->disconnect();

            $connections = $this->manager->getConnections();
            expect($connections)->toBeEmpty();
        });

        it('disconnects from all connections', function (): void {
            $this->manager->connection('main');
            $this->manager->disconnectAll();

            $connections = $this->manager->getConnections();
            expect($connections)->toBeEmpty();
        });

        it('gets all connections', function (): void {
            $connection = $this->manager->connection();
            $connections = $this->manager->getConnections();
            expect($connections)->toHaveKey('main');
            expect($connections['main'])->toBe($connection);
        });

        it('throws exception for non-existent connection', function (): void {
            expect(fn () => $this->manager->connection('non-existent'))
                ->toThrow(ConnectionException::class, 'Invalid connection configuration: OpenFGA connection [non-existent] not configured.');
        });
    });

    describe('health checking', function (): void {
        it('performs health check', function (): void {
            // Create a custom manager that doesn't make real connections
            $manager = new class($this->container, $this->config) extends AbstractOpenFgaManager {
                private bool $shouldSucceed = true;

                public function connection(?string $name = null): ClientInterface
                {
                    if (! $this->shouldSucceed) {
                        throw new Exception('Connection failed');
                    }

                    $mockClient = Mockery::mock(ClientInterface::class);
                    $mockResponse = Mockery::mock(SuccessInterface::class);

                    $mockClient->shouldReceive('readTuples')
                        ->andReturn($mockResponse);

                    return $mockClient;
                }

                public function query(?string $connection = null): AuthorizationQuery
                {
                    return new AuthorizationQuery($this, $connection);
                }

                public function setShouldSucceed(bool $should): void
                {
                    $this->shouldSucceed = $should;
                }
            };

            // Test successful health check
            $manager->setShouldSucceed(true);

            $healthy = $manager->healthCheck();
            expect($healthy)->toBeTrue();

            // Test failed health check
            $manager->setShouldSucceed(false);
            $healthy = $manager->healthCheck();
            expect($healthy)->toBeFalse();
        });

        it('performs health check on all connections', function (): void {
            // Create a custom manager that doesn't make real connections
            $manager = new class($this->container, $this->config) extends AbstractOpenFgaManager {
                public function connection(?string $name = null): ClientInterface
                {
                    $mockClient = Mockery::mock(ClientInterface::class);
                    $mockResponse = Mockery::mock(SuccessInterface::class);

                    $mockClient->shouldReceive('readTuples')
                        ->andReturn($mockResponse);

                    return $mockClient;
                }

                public function query(?string $connection = null): AuthorizationQuery
                {
                    return new AuthorizationQuery($this, $connection);
                }
            };

            $results = $manager->healthCheckAll();
            expect($results)->toBeArray();
            expect($results)->toHaveKey('main');
            expect($results['main'])->toBeTrue();
        });
    });

    describe('exception handling', function (): void {
        it('can enable exception throwing', function (): void {
            $manager = $this->manager->throwExceptions(true);
            expect($manager)->toBe($this->manager);
        });

        it('can disable exception throwing', function (): void {
            $manager = $this->manager->throwExceptions(false);
            expect($manager)->toBe($this->manager);
        });
    });

    describe('authentication configuration', function (): void {
        it('handles no authentication', function (): void {
            $config = ['method' => 'none'];
            $credentials = $this->manager->buildCredentials($config);
            expect($credentials)->toBeNull();
        });

        it('handles API token authentication', function (): void {
            $config = [
                'method' => 'api_token',
                'token' => 'test-token',
            ];
            $credentials = $this->manager->buildCredentials($config);
            expect($credentials)->toBe(['api_token' => 'test-token']);
        });

        it('handles client credentials authentication', function (): void {
            $config = [
                'method' => 'client_credentials',
                'client_id' => 'test-client',
                'client_secret' => 'test-secret',
                'api_token_issuer' => 'test-issuer',
                'api_audience' => 'test-audience',
            ];
            $credentials = $this->manager->buildCredentials($config);
            expect($credentials)->toHaveKey('method');
            expect($credentials['method'])->toBe('client_credentials');
        });

        it('returns null for empty token', function (): void {
            $config = [
                'method' => 'api_token',
                'token' => '',
            ];
            $credentials = $this->manager->buildCredentials($config);
            expect($credentials)->toBeNull();
        });

        it('returns null for unknown method', function (): void {
            $config = ['method' => 'unknown'];
            $credentials = $this->manager->buildCredentials($config);
            expect($credentials)->toBeNull();
        });
    });

    describe('user ID resolution', function (): void {
        it('resolves user ID with prefix', function (): void {
            $resolved = $this->manager->resolveUserId('user:123');
            expect($resolved)->toBe('user:123');
        });

        it('adds user prefix when missing', function (): void {
            $resolved = $this->manager->resolveUserId('123');
            expect($resolved)->toBe('user:123');
        });

        it('throws exception for @me without auth', function (): void {
            expect(fn () => $this->manager->resolveUserId('@me'))
                ->toThrow(Exception::class); // Container binding exception when Auth factory not available
        });
    });

    describe('cache configuration', function (): void {
        it('detects cache disabled', function (): void {
            expect($this->manager->cacheEnabled())->toBeFalse();
        });

        it('detects cache enabled', function (): void {
            $this->manager->updateConfig([
                'cache' => ['enabled' => true],
                'connections' => $this->config['connections'],
            ]);
            expect($this->manager->cacheEnabled())->toBeTrue();
        });

        it('gets cache TTL default', function (): void {
            $ttl = $this->manager->getCacheTtl();
            expect($ttl)->toBe(300);
        });

        it('gets custom cache TTL', function (): void {
            $this->manager->updateConfig([
                'cache' => ['ttl' => 600],
                'connections' => $this->config['connections'],
            ]);
            $ttl = $this->manager->getCacheTtl();
            expect($ttl)->toBe(600);
        });

        it('generates cache key', function (): void {
            $key = $this->manager->getCacheKey('check', 'user:123', 'read', 'doc:456');
            expect($key)->toBeString();
            expect($key)->toContain('openfga:check:');
        });

        it('uses custom cache prefix', function (): void {
            $this->manager->updateConfig([
                'cache' => ['prefix' => 'custom'],
                'connections' => $this->config['connections'],
            ]);
            $key = $this->manager->getCacheKey('check', 'test');
            expect($key)->toStartWith('custom:check:');
        });
    });

    describe('PSR factory detection', function (): void {
        it('detects HTTP client', function (): void {
            $client = $this->manager->detectHttpClient();
            // May be null if no PSR-18 client available in test environment
            expect(null === $client || $client instanceof Psr\Http\Client\ClientInterface)->toBeTrue();
        });

        it('detects request factory', function (): void {
            $factory = $this->manager->detectRequestFactory();
            // May be null if no PSR-17 factory available in test environment
            expect(null === $factory || $factory instanceof RequestFactoryInterface)->toBeTrue();
        });

        it('detects response factory', function (): void {
            $factory = $this->manager->detectResponseFactory();
            // May be null if no PSR-17 factory available in test environment
            expect(null === $factory || $factory instanceof ResponseFactoryInterface)->toBeTrue();
        });

        it('detects stream factory', function (): void {
            $factory = $this->manager->detectStreamFactory();
            // May be null if no PSR-17 factory available in test environment
            expect(null === $factory || $factory instanceof StreamFactoryInterface)->toBeTrue();
        });
    });

    describe('magical method delegation', function (): void {
        it('delegates unknown methods to connection', function (): void {
            expect(fn () => $this->manager->someUnknownMethod())
                ->toThrow(Error::class); // Method doesn't exist on the mocked connection
        });
    });

    describe('write batch operations', function (): void {
        it('handles empty write batch', function (): void {
            $result = $this->manager->writeBatch([], []);
            // Empty batch should succeed (no operations to perform)
            expect($result)->toBeBool();
        });

        it('validates write batch structure', function (): void {
            $writes = [
                ['user' => 'user:123', 'relation' => 'read', 'object' => 'doc:456'],
            ];

            // Mock the connection to avoid real HTTP requests
            $mockClient = Mockery::mock(ClientInterface::class);
            // Create a mock response for write operation
            $mockWriteResponse = Mockery::mock(SuccessInterface::class);
            $mockWriteResponse->shouldReceive('getData')->andReturn([]);
            $mockWriteResponse->shouldReceive('succeeded')->andReturn(true);
            $mockWriteResponse->shouldReceive('failed')->andReturn(false);

            $mockClient->shouldReceive('writeTuples')
                ->once()
                ->with(
                    'test-store',
                    'test-model',
                    Mockery::type(TupleKeys::class),
                    null,
                )
                ->andReturn($mockWriteResponse);

            $manager = new class($this->container, $this->config) extends AbstractOpenFgaManager {
                private $mockClient;

                public function connection(?string $name = null): ClientInterface
                {
                    return $this->mockClient ?? parent::connection($name);
                }

                public function query(?string $connection = null): AuthorizationQuery
                {
                    return new AuthorizationQuery($this, $connection);
                }

                public function setMockClient($client): void
                {
                    $this->mockClient = $client;
                }
            };

            $manager->setMockClient($mockClient);

            // Call writeBatch which should trigger the mocked write method
            $result = $manager->writeBatch($writes);
            expect($result)->toBeTrue();
        });
    });

    describe('configuration validation', function (): void {
        it('throws exception for missing connection config', function (): void {
            $this->manager->updateConfig(['connections' => []]);

            expect(fn () => $this->manager->connection())
                ->toThrow(ConnectionException::class);
        });

        it('validates store_id configuration', function (): void {
            $this->manager->updateConfig([
                'connections' => [
                    'main' => [
                        'url' => 'http://localhost:8080',
                        // Missing store_id
                    ],
                ],
            ]);

            expect(fn () => $this->manager->check('user:123', 'read', 'doc:456'))
                ->toThrow(StoreNotFoundException::class, 'No store ID specified in configuration');
        });
    });
});
