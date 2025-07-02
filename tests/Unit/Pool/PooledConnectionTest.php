<?php

declare(strict_types=1);

use OpenFGA\ClientInterface;
use OpenFGA\Laravel\Pool\PooledConnection;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

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
        // Initial idle time should be very small
        $initialIdleTime = $this->connection->getIdleTime();
        expect($initialIdleTime)->toBeLessThan(0.1);

        // Get client updates last used time
        $this->connection->getClient();

        // Idle time should still be very small
        $newIdleTime = $this->connection->getIdleTime();
        expect($newIdleTime)->toBeLessThan(0.1);
    });

    it('detects expiration based on idle time', function (): void {
        // Fresh connection should not be expired
        expect($this->connection->isExpired(300))->toBeFalse();

        // With 0 max idle time, any connection is considered expired
        expect($this->connection->isExpired(0))->toBeTrue();

        // Very small max idle time (1 second)
        expect($this->connection->isExpired(1))->toBeFalse();
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
        // The updateLastUsed method should reset idle time to near zero
        $this->connection->getClient(); // Ensure it's been used at least once

        // Do some work to ensure time passes
        $sum = 0;

        for ($i = 0; 10000 > $i; ++$i) {
            $sum += sqrt($i);
        }

        // Get idle time before update
        $idleTimeBefore = $this->connection->getIdleTime();
        expect($idleTimeBefore)->toBeGreaterThan(0);

        // Update last used
        $this->connection->updateLastUsed();

        // Idle time should now be very small (near 0)
        $idleTimeAfter = $this->connection->getIdleTime();
        expect($idleTimeAfter)->toBeLessThan($idleTimeBefore);
        expect($idleTimeAfter)->toBeLessThan(0.01); // Should be very small
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
        // Age should be very small for a just-created connection
        $age = $this->connection->getAge();
        expect($age)->toBeGreaterThanOrEqual(0);
        expect($age)->toBeLessThan(1.0); // Should be less than 1 second
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
