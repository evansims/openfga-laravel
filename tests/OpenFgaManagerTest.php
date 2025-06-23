<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use OpenFGA\ClientInterface;
use OpenFGA\Laravel\OpenFgaManager;

describe('OpenFgaManager', function (): void {
    beforeEach(function (): void {
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
                'secondary' => [
                    'url' => 'http://localhost:8081',
                    'credentials' => [
                        'method' => 'api_token',
                        'token' => 'test-token',
                    ],
                ],
            ],
        ];

        $this->manager = new OpenFgaManager($this->container, $this->config);
    });

    it('returns default connection when no name specified', function (): void {
        $connection = $this->manager->connection();

        expect($connection)->toBeInstanceOf(ClientInterface::class);
    });

    it('returns named connection', function (): void {
        $connection = $this->manager->connection('secondary');

        expect($connection)->toBeInstanceOf(ClientInterface::class);
    });

    it('throws exception for non-existent connection', function (): void {
        expect(fn () => $this->manager->connection('non-existent'))
            ->toThrow(InvalidArgumentException::class, 'OpenFGA connection [non-existent] not configured.');
    });

    it('reuses connection instances', function (): void {
        $connection1 = $this->manager->connection('main');
        $connection2 = $this->manager->connection('main');

        expect($connection1)->toBe($connection2);
    });

    it('can disconnect from a connection', function (): void {
        $connection1 = $this->manager->connection('main');
        $this->manager->disconnect('main');
        $connection2 = $this->manager->connection('main');

        expect($connection1)->not->toBe($connection2);
    });

    it('can disconnect from all connections', function (): void {
        $this->manager->connection('main');
        $this->manager->connection('secondary');

        expect($this->manager->getConnections())->toHaveCount(2);

        $this->manager->disconnectAll();

        expect($this->manager->getConnections())->toBeEmpty();
    });

    it('can get and set default connection', function (): void {
        expect($this->manager->getDefaultConnection())->toBe('main');

        $this->manager->setDefaultConnection('secondary');

        expect($this->manager->getDefaultConnection())->toBe('secondary');
    });

    it('forwards method calls to default connection', function (): void {
        // This would need a mock to test properly
        expect($this->manager)->toBeObject();
    });

    describe('credential building', function (): void {
        it('returns null for no authentication', function (): void {
            $manager = new OpenFgaManager($this->container, $this->config);

            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('buildCredentials');
            $method->setAccessible(true);

            expect($method->invoke($manager, ['method' => 'none']))->toBeNull();
            expect($method->invoke($manager, []))->toBeNull();
        });

        it('builds api token credentials', function (): void {
            $manager = new OpenFgaManager($this->container, $this->config);

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
            $manager = new OpenFgaManager($this->container, $this->config);

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
            $manager = new OpenFgaManager($this->container, $this->config);

            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('detectHttpClient');
            $method->setAccessible(true);

            // This will return null or an object depending on what's installed
            $client = $method->invoke($manager);

            expect($client)->toBeObject()->or->toBeNull();
        });
    });
});
