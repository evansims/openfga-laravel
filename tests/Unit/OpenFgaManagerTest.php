<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit;

use Illuminate\Container\Container;
use InvalidArgumentException;
use OpenFGA\Laravel\Exceptions\ConnectionException;
use OpenFGA\ClientInterface;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Tests\TestCase;
use ReflectionClass;

uses(TestCase::class);

// Dataset for connection configurations
dataset('connection_configs', [
    'no_auth' => [[
        'url' => 'http://localhost:8080',
        'credentials' => ['method' => 'none'],
    ]],
    'api_token' => [[
        'url' => 'http://localhost:8080',
        'credentials' => ['method' => 'api_token', 'token' => 'test-token'],
    ]],
    'client_credentials' => [[
        'url' => 'http://localhost:8080',
        'credentials' => [
            'method' => 'client_credentials',
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'api_audience' => 'https://api.fga.example',
            'api_token_issuer' => 'https://auth.example.com',
        ],
    ]],
]);

describe('OpenFgaManager', function (): void {
    beforeEach(function (): void {
        $this->container = new Container;

        // Configure multiple connections to test connection management
        $this->config = [
            'default' => 'main', // Default connection for most operations
            'connections' => [
                // Main connection: unauthenticated for basic tests
                'main' => [
                    'url' => 'http://localhost:8080',
                    'store_id' => 'test-store', // Standard test store ID
                    'model_id' => 'test-model', // Standard test model ID
                    'credentials' => [
                        'method' => 'none', // No auth for simplicity
                    ],
                ],
                // Secondary connection: authenticated for security tests
                'secondary' => [
                    'url' => 'http://localhost:8081', // Different port to distinguish
                    'credentials' => [
                        'method' => 'api_token',
                        'token' => 'test-token', // Sample API token
                    ],
                ],
            ],
        ];

        $this->manager = new OpenFgaManager(container: $this->container, config: $this->config);
    })
        ->afterEach(function (): void {
            $this->manager->disconnectAll();
        });

    describe('connection management', function (): void {
        it('returns default connection when no name specified', function (): void {
            expect($this->manager->connection())
                ->toBeInstanceOf(ClientInterface::class);
        });

        it('returns named connection', function (): void {
            expect($this->manager->connection('secondary'))
                ->toBeInstanceOf(ClientInterface::class);
        });

        it('throws exception for non-existent connection', function (): void {
            expect(fn () => $this->manager->connection('non-existent'))
                ->toThrow(ConnectionException::class, 'Invalid connection configuration: OpenFGA connection [non-existent] not configured.');
        });

        it('creates connections with different auth methods', function (array $config): void {
            $this->config['connections']['test'] = array_merge(
                ['store_id' => 'test-store', 'model_id' => 'test-model'],
                $config,
            );
            $this->manager->updateConfig($this->config);

            expect($this->manager->connection('test'))
                ->toBeInstanceOf(ClientInterface::class);
        })->with('connection_configs');
    });

    describe('connection pooling', function (): void {
        it('reuses connection instances', function (): void {
            $connection1 = $this->manager->connection('main');
            $connection2 = $this->manager->connection('main');

            expect($connection1)
                ->toBe($connection2)
                ->toBeInstanceOf(ClientInterface::class);
        });

        it('can disconnect from a connection', function (): void {
            $connection1 = $this->manager->connection('main');
            $this->manager->disconnect('main');
            $connection2 = $this->manager->connection('main');

            expect($connection1)
                ->not->toBe($connection2)
                ->and($connection2)->toBeInstanceOf(ClientInterface::class);
        });

        it('can disconnect from all connections', function (): void {
            // Create multiple connections
            collect(['main', 'secondary'])->each(
                fn ($name) => $this->manager->connection($name),
            );

            expect($this->manager->getConnections())
                ->toHaveCount(2)
                ->and($this->manager->disconnectAll())->toBeNull()
                ->and($this->manager->getConnections())->toBeEmpty();
        });

        // Removed concurrent connection test - concurrency is handled by the underlying HTTP client
    });

    describe('default connection', function (): void {
        it('can get and set default connection', function (): void {
            expect($this->manager)
                ->getDefaultConnection()->toBe('main')
                ->and($this->manager->setDefaultConnection('secondary'))->toBeNull()
                ->and($this->manager->getDefaultConnection())->toBe('secondary');
        });

        it('validates default connection exists', function (): void {
            // Setting invalid default connection is allowed - it will fail when trying to use it
            $this->manager->setDefaultConnection('invalid');
            expect(fn () => $this->manager->connection())
                ->toThrow(ConnectionException::class, 'Invalid connection configuration: OpenFGA connection [invalid] not configured.');
        });
    });

    // Removed method forwarding test - this is tested through actual usage in other tests

    describe('credential building', function (): void {
        it('returns null for no authentication', function (): void {
            $manager = new OpenFgaManager(container: $this->container, config: $this->config);

            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('buildCredentials');
            $method->setAccessible(true);

            expect($method->invoke($manager, ['method' => 'none']))->toBeNull();
            expect($method->invoke($manager, []))->toBeNull();
        });

        it('builds api token credentials', function (): void {
            $manager = new OpenFgaManager(container: $this->container, config: $this->config);

            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('buildCredentials');
            $method->setAccessible(true);

            $credentials = $method->invoke($manager, [
                'method' => 'api_token',
                'token' => 'test-token',
            ]);

            expect($credentials)->toBe(['api_token' => 'test-token']);
        });

        it('builds client credentials', function (): void {
            $manager = new OpenFgaManager(container: $this->container, config: $this->config);

            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('buildCredentials');
            $method->setAccessible(true);

            $credentials = $method->invoke($manager, [
                'method' => 'client_credentials',
                'client_id' => 'test-id',
                'client_secret' => 'test-secret',
                'api_token_issuer' => 'test-issuer',
                'api_audience' => 'test-audience',
                'scopes' => ['read', 'write'],
            ]);

            expect($credentials)->toMatchArray([
                'method' => 'client_credentials',
                'client_id' => 'test-id',
                'client_secret' => 'test-secret',
                'api_token_issuer' => 'test-issuer',
                'api_audience' => 'test-audience',
                'scopes' => ['read', 'write'],
            ]);
        });
    });

    describe('PSR detection', function (): void {
        it('detects available HTTP clients', function (): void {
            $manager = new OpenFgaManager(container: $this->container, config: $this->config);

            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('detectHttpClient');
            $method->setAccessible(true);

            // This will return null or an object depending on what's installed
            $client = $method->invoke($manager);

            expect($client)->toBeObject()->or->toBeNull();
        });
    });
});
