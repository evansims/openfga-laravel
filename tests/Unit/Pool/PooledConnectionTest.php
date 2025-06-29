<?php

declare(strict_types=1);

use OpenFGA\ClientInterface;
use OpenFGA\Laravel\Pool\PooledConnection;

describe('PooledConnection', function (): void {
    beforeEach(function (): void {
        $this->mockClient = mock(ClientInterface::class);
        $this->connection = new PooledConnection($this->mockClient, 'test_id');
    });

    it('initializes with correct values', function (): void {
        expect($this->connection->getId())->toBe('test_id');
        expect($this->connection->isHealthy())->toBeTrue();
        expect($this->connection->getUseCount())->toBe(0);
        expect($this->connection->getAge())->toBeGreaterThan(0);
        expect($this->connection->getIdleTime())->toBeGreaterThan(0);
    });

    it('returns client and updates usage statistics', function (): void {
        $client = $this->connection->getClient();

        expect($client)->toBe($this->mockClient);
        expect($this->connection->getUseCount())->toBe(1);

        // Use again
        $this->connection->getClient();
        expect($this->connection->getUseCount())->toBe(2);
    });

    it('tracks idle time correctly', function (): void {
        $initialIdleTime = $this->connection->getIdleTime();

        // Sleep for a short time to advance idle time
        usleep(1000); // 1ms

        expect($this->connection->getIdleTime())->toBeGreaterThan($initialIdleTime);

        // Get client to reset idle time
        $this->connection->getClient();

        expect($this->connection->getIdleTime())->toBeLessThan($initialIdleTime);
    });

    it('detects expiration based on idle time', function (): void {
        expect($this->connection->isExpired(300))->toBeFalse();
        expect($this->connection->isExpired(0))->toBeTrue();

        // Very small max idle time should cause expiration
        usleep(1000); // 1ms
        expect($this->connection->isExpired(0))->toBeTrue();
    });

    it('can be marked as unhealthy', function (): void {
        expect($this->connection->isHealthy())->toBeTrue();

        $this->connection->markUnhealthy();

        expect($this->connection->isHealthy())->toBeFalse();
    });

    it('can be closed', function (): void {
        expect($this->connection->isHealthy())->toBeTrue();

        $this->connection->close();

        expect($this->connection->isHealthy())->toBeFalse();
    });

    it('updates last used timestamp', function (): void {
        $initialIdleTime = $this->connection->getIdleTime();

        usleep(1000); // 1ms

        $this->connection->updateLastUsed();

        expect($this->connection->getIdleTime())->toBeLessThan($initialIdleTime);
    });

    it('provides comprehensive statistics', function (): void {
        $this->connection->getClient(); // Increment use count

        $stats = $this->connection->getStats();

        expect($stats)->toHaveKeys(['id', 'age', 'idle_time', 'use_count', 'healthy']);
        expect($stats['id'])->toBe('test_id');
        expect($stats['age'])->toBeFloat();
        expect($stats['idle_time'])->toBeFloat();
        expect($stats['use_count'])->toBe(1);
        expect($stats['healthy'])->toBeTrue();
    });

    it('tracks age correctly', function (): void {
        $age1 = $this->connection->getAge();

        usleep(1000); // 1ms

        $age2 = $this->connection->getAge();

        expect($age2)->toBeGreaterThan($age1);
    });

    it('handles multiple client retrievals', function (): void {
        // Get client multiple times
        for ($i = 0; 5 > $i; ++$i) {
            $client = $this->connection->getClient();
            expect($client)->toBe($this->mockClient);
        }

        expect($this->connection->getUseCount())->toBe(5);
    });

    it('maintains health status after multiple uses', function (): void {
        // Use connection multiple times
        for ($i = 0; 10 > $i; ++$i) {
            $this->connection->getClient();
        }

        expect($this->connection->isHealthy())->toBeTrue();
        expect($this->connection->getUseCount())->toBe(10);
    });
});
