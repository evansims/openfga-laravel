<?php

declare(strict_types=1);

use OpenFGA\Laravel\Exceptions\{AbstractOpenFgaException, ConnectionPoolException};
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('ConnectionPoolException', function (): void {
    it('extends AbstractOpenFgaException', function (): void {
        $exception = new ConnectionPoolException('Test message');

        expect($exception)->toBeInstanceOf(AbstractOpenFgaException::class);
        expect($exception->getMessage())->toBe('Test message');
    });

    it('creates initialization failed exception', function (): void {
        $exception = ConnectionPoolException::initializationFailed('Database connection failed');

        expect($exception)->toBeInstanceOf(ConnectionPoolException::class);
        expect($exception->getMessage())->toBe('Failed to initialize connection pool: Database connection failed');
    });

    it('creates max connections reached exception', function (): void {
        $exception = ConnectionPoolException::maxConnectionsReached(10);

        expect($exception)->toBeInstanceOf(ConnectionPoolException::class);
        expect($exception->getMessage())->toBe('Connection pool has reached maximum capacity of 10 connections');
    });

    it('creates timeout exception', function (): void {
        $exception = ConnectionPoolException::timeout(30);

        expect($exception)->toBeInstanceOf(ConnectionPoolException::class);
        expect($exception->getMessage())->toBe('Timeout waiting for available connection after 30 seconds');
    });

    it('handles zero timeout', function (): void {
        $exception = ConnectionPoolException::timeout(0);

        expect($exception->getMessage())->toBe('Timeout waiting for available connection after 0 seconds');
    });

    it('handles large connection count', function (): void {
        $exception = ConnectionPoolException::maxConnectionsReached(9999);

        expect($exception->getMessage())->toBe('Connection pool has reached maximum capacity of 9999 connections');
    });

    it('handles empty initialization reason', function (): void {
        $exception = ConnectionPoolException::initializationFailed('');

        expect($exception->getMessage())->toBe('Failed to initialize connection pool: ');
    });

    it('handles complex initialization reason', function (): void {
        $reason = 'Authentication failed: Invalid credentials provided for user admin@example.com';
        $exception = ConnectionPoolException::initializationFailed($reason);

        expect($exception->getMessage())->toBe('Failed to initialize connection pool: ' . $reason);
    });

    it('can be constructed directly', function (): void {
        $exception = new ConnectionPoolException(message: 'Custom error message', code: 500);

        expect($exception->getMessage())->toBe('Custom error message');
        expect($exception->getCode())->toBe(500);
    });

    it('can be constructed with previous exception', function (): void {
        $previous = new RuntimeException('Previous error');
        $exception = new ConnectionPoolException(
            message: 'Current error',
            code: 0,
            previous: $previous,
        );

        expect($exception->getMessage())->toBe('Current error');
        expect($exception->getPrevious())->toBe($previous);
    });

    it('static methods create exceptions with default code', function (): void {
        $initException = ConnectionPoolException::initializationFailed('test');
        $maxException = ConnectionPoolException::maxConnectionsReached(5);
        $timeoutException = ConnectionPoolException::timeout(10);

        expect($initException->getCode())->toBe(0);
        expect($maxException->getCode())->toBe(0);
        expect($timeoutException->getCode())->toBe(0);
    });

    it('preserves exception properties', function (): void {
        $exception = ConnectionPoolException::timeout(15);

        expect($exception->getFile())->toBeString();
        expect($exception->getLine())->toBeInt();
        expect($exception->getTrace())->toBeArray();
        expect($exception->getTraceAsString())->toBeString();
    });
});
