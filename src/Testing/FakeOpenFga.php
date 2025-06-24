<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use PHPUnit\Framework\{Assert, ExpectationFailedException, GeneratorNotSupportedException};
use RuntimeException;
use Throwable;

use function count;
use function sprintf;

/**
 * Fake implementation of OpenFGA for testing purposes.
 */
final class FakeOpenFga
{
    /**
     * Recorded check operations.
     *
     * @var array<int, array{user: string, relation: string, object: string, result: bool}>
     */
    private array $checks = [];

    /**
     * Recorded expand operations.
     *
     * @var array<int, array{object: string, relation: string, result: array<string, mixed>}>
     */
    private array $expandCalls = [];

    /**
     * Exception to throw when shouldFail is true.
     */
    private ?Throwable $failureException = null;

    /**
     * Recorded list objects operations.
     *
     * @var array<int, array{user: string, relation: string, type: string, result: array<string>}>
     */
    private array $listObjectsCalls = [];

    /**
     * Mock responses for list objects operations.
     *
     * @var array<string, array<string>>
     */
    private array $mockListObjects = [];

    /**
     * Mock responses for specific check operations.
     *
     * @var array<string, bool>
     */
    private array $mockResponses = [];

    /**
     * Whether to throw exceptions for certain operations.
     */
    private bool $shouldFail = false;

    /**
     * Stored relationship tuples.
     *
     * @var array<int, array{user: string, relation: string, object: string}>
     */
    private array $tuples = [];

    /**
     * Recorded write operations.
     *
     * @var array<int, array{
     *     writes: array<int, array{user: string, relation: string, object: string}>,
     *     deletes: array<int, array{user: string, relation: string, object: string}>
     * }>
     */
    private array $writes = [];

    /**
     * Assert that a write batch was performed.
     *
     * @param ?string $message
     *
     * @throws ExpectationFailedException
     * @throws GeneratorNotSupportedException
     */
    public function assertBatchWritten(?string $message = null): void
    {
        $message ??= 'Failed asserting that a batch write was performed';

        Assert::assertNotEmpty($this->writes, $message);
    }

    /**
     * Assert the number of checks performed.
     *
     * @param int     $count
     * @param ?string $message
     *
     * @throws ExpectationFailedException
     */
    public function assertCheckCount(int $count, ?string $message = null): void
    {
        $actual = count($this->checks);
        $message ??= sprintf('Failed asserting that [%d] checks were performed. Actual: [%d]', $count, $actual);

        Assert::assertSame($count, $actual, $message);
    }

    /**
     * Assert that a check was performed.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $object
     * @param ?string $message
     *
     * @throws ExpectationFailedException
     */
    public function assertChecked(string $user, string $relation, string $object, ?string $message = null): void
    {
        $message ??= sprintf('Failed asserting that check [%s] was performed for [%s] on [%s]', $relation, $user, $object);

        Assert::assertTrue(
            collect($this->checks)->contains(static fn ($check): bool => $check['user'] === $user
                    && $check['relation'] === $relation
                    && $check['object'] === $object),
            $message,
        );
    }

    // Assertion methods

    /**
     * Assert that a permission was granted.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $object
     * @param ?string $message
     *
     * @throws ExpectationFailedException
     */
    public function assertGranted(string $user, string $relation, string $object, ?string $message = null): void
    {
        $message ??= sprintf('Failed asserting that permission [%s] was granted to [%s] on [%s]', $relation, $user, $object);

        Assert::assertTrue(
            collect($this->tuples)->contains(static fn ($tuple): bool => $tuple['user'] === $user
                    && $tuple['relation'] === $relation
                    && $tuple['object'] === $object),
            $message,
        );
    }

    /**
     * Assert that no batch writes were performed.
     *
     * @param ?string $message
     *
     * @throws ExpectationFailedException
     * @throws GeneratorNotSupportedException
     */
    public function assertNoBatchWrites(?string $message = null): void
    {
        $message ??= 'Failed asserting that no batch writes were performed';

        Assert::assertEmpty($this->writes, $message);
    }

    /**
     * Assert that no checks were performed.
     *
     * @param ?string $message
     *
     * @throws ExpectationFailedException
     */
    public function assertNoChecks(?string $message = null): void
    {
        $this->assertCheckCount(0, $message);
    }

    /**
     * Assert that a check was not performed.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $object
     * @param ?string $message
     *
     * @throws ExpectationFailedException
     */
    public function assertNotChecked(string $user, string $relation, string $object, ?string $message = null): void
    {
        $message ??= sprintf('Failed asserting that check [%s] was not performed for [%s] on [%s]', $relation, $user, $object);

        Assert::assertFalse(
            collect($this->checks)->contains(static fn ($check): bool => $check['user'] === $user
                    && $check['relation'] === $relation
                    && $check['object'] === $object),
            $message,
        );
    }

    /**
     * Assert that a permission was not granted.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $object
     * @param ?string $message
     *
     * @throws ExpectationFailedException
     */
    public function assertNotGranted(string $user, string $relation, string $object, ?string $message = null): void
    {
        $message ??= sprintf('Failed asserting that permission [%s] was not granted to [%s] on [%s]', $relation, $user, $object);

        Assert::assertFalse(
            collect($this->tuples)->contains(static fn ($tuple): bool => $tuple['user'] === $user
                    && $tuple['relation'] === $relation
                    && $tuple['object'] === $object),
            $message,
        );
    }

    /**
     * Check if a user has a permission.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     *
     * @throws RuntimeException
     * @throws Throwable
     */
    public function check(string $user, string $relation, string $object): bool
    {
        if ($this->shouldFail) {
            throw $this->failureException ?? new RuntimeException('Fake OpenFGA check failed');
        }

        $key = $this->getCacheKey($user, $relation, $object);

        // Return mocked response if available
        if (isset($this->mockResponses[$key])) {
            $result = $this->mockResponses[$key];
        } else {
            // Check if tuple exists
            $result = collect($this->tuples)->contains(static fn ($tuple): bool => $tuple['user'] === $user
                    && $tuple['relation'] === $relation
                    && $tuple['object'] === $object);
        }

        // Record the check operation
        $this->checks[] = ['user' => $user, 'relation' => $relation, 'object' => $object, 'result' => $result];

        return $result;
    }

    /**
     * Expand a relation to see all users who have it.
     *
     * @param string $object
     * @param string $relation
     *
     * @throws RuntimeException
     * @throws Throwable
     *
     * @return array<string, mixed>
     */
    public function expand(string $object, string $relation): array
    {
        if ($this->shouldFail) {
            throw $this->failureException ?? new RuntimeException('Fake OpenFGA expand failed');
        }

        // Simple expansion - return all users with this relation on this object
        $users = collect($this->tuples)
            ->filter(static fn ($tuple): bool => $tuple['object'] === $object && $tuple['relation'] === $relation)
            ->pluck('user')
            ->unique()
            ->values()
            ->toArray();

        $result = [
            'tree' => [
                'root' => [
                    'name' => $object . '#' . $relation,
                    'leaf' => [
                        'users' => $users,
                    ],
                ],
            ],
        ];

        // Record the operation
        $this->expandCalls[] = ['object' => $object, 'relation' => $relation, 'result' => $result];

        return $result;
    }

    /**
     * Get all recorded checks.
     *
     * @return array<int, array{user: string, relation: string, object: string, result: bool}>
     */
    public function getChecks(): array
    {
        return $this->checks;
    }

    /**
     * Get all recorded expand calls.
     *
     * @return array<int, array{object: string, relation: string, result: array<string, mixed>}>
     */
    public function getExpandCalls(): array
    {
        return $this->expandCalls;
    }

    /**
     * Get all recorded list objects calls.
     *
     * @return array<int, array{user: string, relation: string, type: string, result: array<string>}>
     */
    public function getListObjectsCalls(): array
    {
        return $this->listObjectsCalls;
    }

    /**
     * Get all stored tuples.
     *
     * @return array<int, array{user: string, relation: string, object: string}>
     */
    public function getTuples(): array
    {
        return $this->tuples;
    }

    /**
     * Get all recorded write operations.
     *
     * @return array<int, array{
     *     writes: array<int, array{user: string, relation: string, object: string}>,
     *     deletes: array<int, array{user: string, relation: string, object: string}>
     * }>
     */
    public function getWrites(): array
    {
        return $this->writes;
    }

    /**
     * Grant a permission to a user.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     */
    public function grant(string $user, string $relation, string $object): void
    {
        $this->tuples[] = ['user' => $user, 'relation' => $relation, 'object' => $object];
    }

    /**
     * List objects that a user has a specific relation to.
     *
     * @param string $user
     * @param string $relation
     * @param string $type
     *
     * @throws RuntimeException
     * @throws Throwable
     *
     * @return array<string>
     */
    public function listObjects(string $user, string $relation, string $type): array
    {
        if ($this->shouldFail) {
            throw $this->failureException ?? new RuntimeException('Fake OpenFGA listObjects failed');
        }

        $key = $this->getListObjectsKey($user, $relation, $type);

        // Return mocked response if available
        if (isset($this->mockListObjects[$key])) {
            $result = $this->mockListObjects[$key];
        } else {
            // Filter tuples to find matching objects
            /** @var array<string> */
            $result = collect($this->tuples)
                ->filter(static fn (array $tuple): bool => $tuple['user'] === $user
                        && $tuple['relation'] === $relation
                        && str_starts_with($tuple['object'], $type . ':'))
                ->pluck('object')
                ->unique()
                ->values()
                ->toArray();
        }

        // Record the operation
        $this->listObjectsCalls[] = ['user' => $user, 'relation' => $relation, 'type' => $type, 'result' => $result];

        return $result;
    }

    /**
     * Mock a specific check response.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param bool   $result
     */
    public function mockCheck(string $user, string $relation, string $object, bool $result): self
    {
        $key = $this->getCacheKey($user, $relation, $object);
        $this->mockResponses[$key] = $result;

        return $this;
    }

    /**
     * Mock a specific list objects response.
     *
     * @param array<string> $result
     * @param string        $user
     * @param string        $relation
     * @param string        $type
     */
    public function mockListObjects(string $user, string $relation, string $type, array $result): self
    {
        $key = $this->getListObjectsKey($user, $relation, $type);
        $this->mockListObjects[$key] = $result;

        return $this;
    }

    /**
     * Clear all recorded data.
     */
    public function reset(): self
    {
        $this->tuples = [];
        $this->checks = [];
        $this->listObjectsCalls = [];
        $this->expandCalls = [];
        $this->writes = [];
        $this->mockResponses = [];
        $this->mockListObjects = [];
        $this->shouldFail = false;
        $this->failureException = null;

        return $this;
    }

    /**
     * Revoke a permission from a user.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     */
    public function revoke(string $user, string $relation, string $object): void
    {
        $this->tuples = array_filter($this->tuples, static fn ($tuple): bool => ! ($tuple['user'] === $user && $tuple['relation'] === $relation && $tuple['object'] === $object));
    }

    /**
     * Make the fake throw exceptions for operations.
     *
     * @param ?Throwable $exception
     */
    public function shouldFail(?Throwable $exception = null): self
    {
        $this->shouldFail = true;
        $this->failureException = $exception;

        return $this;
    }

    /**
     * Reset failure state.
     */
    public function shouldSucceed(): self
    {
        $this->shouldFail = false;
        $this->failureException = null;

        return $this;
    }

    /**
     * Perform batch write operations.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $writes
     * @param array<int, array{user: string, relation: string, object: string}> $deletes
     *
     * @throws RuntimeException
     * @throws Throwable
     */
    public function writeBatch(array $writes = [], array $deletes = []): void
    {
        if ($this->shouldFail) {
            throw $this->failureException ?? new RuntimeException('Fake OpenFGA writeBatch failed');
        }

        // Record the operation
        $this->writes[] = [
            'writes' => array_values($writes),
            'deletes' => array_values($deletes),
        ];

        // Apply writes
        foreach ($writes as $write) {
            $this->tuples[] = $write;
        }

        // Apply deletes
        foreach ($deletes as $delete) {
            $this->tuples = array_filter($this->tuples, static fn ($tuple): bool => ! ($tuple['user'] === $delete['user']
                    && $tuple['relation'] === $delete['relation']
                    && $tuple['object'] === $delete['object']));
        }
    }

    /**
     * Get cache key for check operations.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     */
    private function getCacheKey(string $user, string $relation, string $object): string
    {
        return sprintf('%s:%s:%s', $user, $relation, $object);
    }

    /**
     * Get cache key for list objects operations.
     *
     * @param string $user
     * @param string $relation
     * @param string $type
     */
    private function getListObjectsKey(string $user, string $relation, string $type): string
    {
        return sprintf('%s:%s:%s', $user, $relation, $type);
    }
}
