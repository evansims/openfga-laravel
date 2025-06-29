<?php

declare(strict_types=1);

use OpenFGA\ClientInterface;
use OpenFGA\Laravel\Exceptions\ConnectionPoolException;
use OpenFGA\Laravel\Pool\{ConnectionPool, PooledConnection};
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('ConnectionPool', function (): void {
    beforeEach(function (): void {
        $this->config = [
            'url' => 'http://localhost:8080',
            'max_connections' => 5,
            'min_connections' => 2,
            'max_idle_time' => 300,
            'connection_timeout' => 1,
            'credentials' => [
                'method' => 'none',
            ],
            'retries' => [
                'max' => 3,
            ],
        ];
    });

    it('initializes pool with minimum connections', function (): void {
        $pool = new ConnectionPool($this->config);

        $stats = $pool->getStats();
        expect($stats['total'])->toBe(2);
        expect($stats['available'])->toBe(2);
        expect($stats['in_use'])->toBe(0);
        expect($stats['created'])->toBe(2);
    });

    it('can acquire and release connections', function (): void {
        $pool = new ConnectionPool($this->config);

        $connection = $pool->acquire();

        expect($connection)->toBeInstanceOf(PooledConnection::class);

        $stats = $pool->getStats();
        expect($stats['in_use'])->toBe(1);
        expect($stats['available'])->toBe(1);
        expect($stats['acquired'])->toBe(1);

        $pool->release($connection);

        $stats = $pool->getStats();
        expect($stats['in_use'])->toBe(0);
        expect($stats['available'])->toBe(2);
        expect($stats['released'])->toBe(1);
    });

    it('creates new connections when available pool is empty', function (): void {
        $pool = new ConnectionPool($this->config);

        // Acquire all initial connections
        $conn1 = $pool->acquire();
        $conn2 = $pool->acquire();

        $stats = $pool->getStats();
        expect($stats['in_use'])->toBe(2);
        expect($stats['available'])->toBe(0);

        // Acquire a third connection - should create new one
        $conn3 = $pool->acquire();

        $stats = $pool->getStats();
        expect($stats['total'])->toBe(3);
        expect($stats['in_use'])->toBe(3);
        expect($stats['created'])->toBe(3);
    });

    it('throws exception when max connections reached and timeout occurs', function (): void {
        $config = array_merge($this->config, [
            'max_connections' => 2,
            'min_connections' => 2,
            'connection_timeout' => 1, // Very short timeout (1 second) - more reliable than 0
        ]);

        $pool = new ConnectionPool($config);

        // Acquire all connections
        $conn1 = $pool->acquire();
        $conn2 = $pool->acquire();

        // Verify pool is at capacity
        $stats = $pool->getStats();
        expect($stats['in_use'])->toBe(2);
        expect($stats['available'])->toBe(0);

        // Try to acquire another connection - should timeout
        expect(fn () => $pool->acquire())
            ->toThrow(ConnectionPoolException::class, 'Timeout waiting for available connection');
    });

    it('calculates utilization correctly', function (): void {
        $pool = new ConnectionPool($this->config);

        $stats = $pool->getStats();
        expect($stats['utilization'])->toBe(0.0);

        $conn1 = $pool->acquire();
        $stats = $pool->getStats();
        expect($stats['utilization'])->toBe(50.0); // 1 of 2 connections in use

        $conn2 = $pool->acquire();
        $stats = $pool->getStats();
        expect($stats['utilization'])->toBe(100.0); // 2 of 2 connections in use
    });

    it('performs health checks', function (): void {
        $pool = new ConnectionPool($this->config);

        $health = $pool->healthCheck();
        expect($health['healthy'])->toBe(2);
        expect($health['unhealthy'])->toBe(0);
        expect($health['total'])->toBe(2);
    });

    it('executes callbacks with automatic connection management', function (): void {
        $pool = new ConnectionPool($this->config);

        $result = $pool->execute(function (ClientInterface $client): string {
            expect($client)->toBeInstanceOf(ClientInterface::class);

            return 'test_result';
        });

        expect($result)->toBe('test_result');

        // Connection should be released automatically
        $stats = $pool->getStats();
        expect($stats['in_use'])->toBe(0);
        expect($stats['available'])->toBe(2);
        expect($stats['acquired'])->toBe(1);
        expect($stats['released'])->toBe(1);
    });

    it('handles exceptions in execute callback', function (): void {
        $pool = new ConnectionPool($this->config);

        expect(function () use ($pool): void {
            $pool->execute(function (ClientInterface $client): void {
                throw new RuntimeException('Test exception');
            });
        })->toThrow(RuntimeException::class, 'Test exception');

        // Connection should still be released even after exception
        $stats = $pool->getStats();
        expect($stats['in_use'])->toBe(0);
        expect($stats['available'])->toBe(2);
    });

    it('shuts down cleanly', function (): void {
        $pool = new ConnectionPool($this->config);

        $conn = $pool->acquire();

        $pool->shutdown();

        $stats = $pool->getStats();
        expect($stats['total'])->toBe(0);
        expect($stats['available'])->toBe(0);
        expect($stats['in_use'])->toBe(0);
        expect($stats['destroyed'])->toBeGreaterThan(0);
    });

    it('supports client credentials authentication', function (): void {
        $config = array_merge($this->config, [
            'credentials' => [
                'client_id' => 'test_client_id',
                'client_secret' => 'test_client_secret',
                'audience' => 'test_audience',
                'issuer' => 'test_issuer',
            ],
        ]);

        $pool = new ConnectionPool($config);

        $stats = $pool->getStats();
        expect($stats['created'])->toBe(2);
        expect($stats['errors'])->toBe(0);
    });

    it('supports token authentication', function (): void {
        $config = array_merge($this->config, [
            'credentials' => [
                'api_token' => 'test_api_token',
            ],
        ]);

        $pool = new ConnectionPool($config);

        $stats = $pool->getStats();
        expect($stats['created'])->toBe(2);
        expect($stats['errors'])->toBe(0);
    });

    it('uses default values for missing config', function (): void {
        $pool = new ConnectionPool([
            'url' => 'http://localhost:8080',
        ]);

        $stats = $pool->getStats();
        expect($stats['total'])->toBe(2); // default min_connections
    });

    it('tracks total connections correctly', function (): void {
        $pool = new ConnectionPool($this->config);

        expect($pool->getTotalConnections())->toBe(2);

        $conn1 = $pool->acquire();
        expect($pool->getTotalConnections())->toBe(2);

        $conn2 = $pool->acquire();
        expect($pool->getTotalConnections())->toBe(2);

        $conn3 = $pool->acquire(); // Creates new connection
        expect($pool->getTotalConnections())->toBe(3);
    });
});
