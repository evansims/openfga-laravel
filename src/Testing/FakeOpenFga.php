<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use OpenFGA\Laravel\OpenFgaManager;
use PHPUnit\Framework\Assert;

/**
 * Fake implementation of OpenFGA for testing purposes.
 */
class FakeOpenFga
{
    /**
     * Stored relationship tuples.
     *
     * @var array<int, array{user: string, relation: string, object: string}>
     */
    protected array $tuples = [];

    /**
     * Recorded check operations.
     *
     * @var array<int, array{user: string, relation: string, object: string, result: bool}>
     */
    protected array $checks = [];

    /**
     * Recorded list objects operations.
     *
     * @var array<int, array{user: string, relation: string, type: string, result: array<string>}>
     */
    protected array $listObjectsCalls = [];

    /**
     * Recorded expand operations.
     *
     * @var array<int, array{object: string, relation: string, result: array}>
     */
    protected array $expandCalls = [];

    /**
     * Recorded write operations.
     *
     * @var array<int, array{writes: array, deletes: array}>
     */
    protected array $writes = [];

    /**
     * Mock responses for specific check operations.
     *
     * @var array<string, bool>
     */
    protected array $mockResponses = [];

    /**
     * Mock responses for list objects operations.
     *
     * @var array<string, array<string>>
     */
    protected array $mockListObjects = [];

    /**
     * Whether to throw exceptions for certain operations.
     */
    protected bool $shouldFail = false;

    /**
     * Exception to throw when shouldFail is true.
     */
    protected ?\Throwable $failureException = null;

    /**
     * Grant a permission to a user.
     */
    public function grant(string $user, string $relation, string $object): void
    {
        $this->tuples[] = compact('user', 'relation', 'object');
    }

    /**
     * Revoke a permission from a user.
     */
    public function revoke(string $user, string $relation, string $object): void
    {
        $this->tuples = array_filter($this->tuples, function ($tuple) use ($user, $relation, $object) {
            return !($tuple['user'] === $user && $tuple['relation'] === $relation && $tuple['object'] === $object);
        });
    }

    /**
     * Check if a user has a permission.
     */
    public function check(string $user, string $relation, string $object): bool
    {
        if ($this->shouldFail) {
            throw $this->failureException ?? new \RuntimeException('Fake OpenFGA check failed');
        }

        $key = $this->getCacheKey($user, $relation, $object);
        
        // Return mocked response if available
        if (isset($this->mockResponses[$key])) {
            $result = $this->mockResponses[$key];
        } else {
            // Check if tuple exists
            $result = collect($this->tuples)->contains(function ($tuple) use ($user, $relation, $object) {
                return $tuple['user'] === $user
                    && $tuple['relation'] === $relation
                    && $tuple['object'] === $object;
            });
        }

        // Record the check operation
        $this->checks[] = compact('user', 'relation', 'object', 'result');

        return $result;
    }

    /**
     * List objects that a user has a specific relation to.
     *
     * @return array<string>
     */
    public function listObjects(string $user, string $relation, string $type): array
    {
        if ($this->shouldFail) {
            throw $this->failureException ?? new \RuntimeException('Fake OpenFGA listObjects failed');
        }

        $key = $this->getListObjectsKey($user, $relation, $type);

        // Return mocked response if available
        if (isset($this->mockListObjects[$key])) {
            $result = $this->mockListObjects[$key];
        } else {
            // Filter tuples to find matching objects
            $result = collect($this->tuples)
                ->filter(function ($tuple) use ($user, $relation, $type) {
                    return $tuple['user'] === $user
                        && $tuple['relation'] === $relation
                        && str_starts_with($tuple['object'], $type . ':');
                })
                ->pluck('object')
                ->unique()
                ->values()
                ->toArray();
        }

        // Record the operation
        $this->listObjectsCalls[] = compact('user', 'relation', 'type', 'result');

        return $result;
    }

    /**
     * Expand a relation to see all users who have it.
     *
     * @return array<string, mixed>
     */
    public function expand(string $object, string $relation): array
    {
        if ($this->shouldFail) {
            throw $this->failureException ?? new \RuntimeException('Fake OpenFGA expand failed');
        }

        // Simple expansion - return all users with this relation on this object
        $users = collect($this->tuples)
            ->filter(function ($tuple) use ($object, $relation) {
                return $tuple['object'] === $object && $tuple['relation'] === $relation;
            })
            ->pluck('user')
            ->unique()
            ->values()
            ->toArray();

        $result = [
            'tree' => [
                'root' => [
                    'name' => $object . '#' . $relation,
                    'leaf' => [
                        'users' => $users
                    ]
                ]
            ]
        ];

        // Record the operation
        $this->expandCalls[] = compact('object', 'relation', 'result');

        return $result;
    }

    /**
     * Perform batch write operations.
     *
     * @param array<array{user: string, relation: string, object: string}> $writes
     * @param array<array{user: string, relation: string, object: string}> $deletes
     */
    public function writeBatch(array $writes = [], array $deletes = []): void
    {
        if ($this->shouldFail) {
            throw $this->failureException ?? new \RuntimeException('Fake OpenFGA writeBatch failed');
        }

        // Record the operation
        $this->writes[] = compact('writes', 'deletes');

        // Apply writes
        foreach ($writes as $write) {
            $this->tuples[] = $write;
        }

        // Apply deletes
        foreach ($deletes as $delete) {
            $this->tuples = array_filter($this->tuples, function ($tuple) use ($delete) {
                return !($tuple['user'] === $delete['user'] 
                    && $tuple['relation'] === $delete['relation'] 
                    && $tuple['object'] === $delete['object']);
            });
        }
    }

    /**
     * Mock a specific check response.
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
     */
    public function mockListObjects(string $user, string $relation, string $type, array $result): self
    {
        $key = $this->getListObjectsKey($user, $relation, $type);
        $this->mockListObjects[$key] = $result;

        return $this;
    }

    /**
     * Make the fake throw exceptions for operations.
     */
    public function shouldFail(?\Throwable $exception = null): self
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
     * Get cache key for check operations.
     */
    protected function getCacheKey(string $user, string $relation, string $object): string
    {
        return sprintf('%s:%s:%s', $user, $relation, $object);
    }

    /**
     * Get cache key for list objects operations.
     */
    protected function getListObjectsKey(string $user, string $relation, string $type): string
    {
        return sprintf('%s:%s:%s', $user, $relation, $type);
    }

    // Assertion methods

    /**
     * Assert that a permission was granted.
     */
    public function assertGranted(string $user, string $relation, string $object, ?string $message = null): void
    {
        $message = $message ?? "Failed asserting that permission [{$relation}] was granted to [{$user}] on [{$object}]";

        Assert::assertTrue(
            collect($this->tuples)->contains(function ($tuple) use ($user, $relation, $object) {
                return $tuple['user'] === $user
                    && $tuple['relation'] === $relation
                    && $tuple['object'] === $object;
            }),
            $message
        );
    }

    /**
     * Assert that a permission was not granted.
     */
    public function assertNotGranted(string $user, string $relation, string $object, ?string $message = null): void
    {
        $message = $message ?? "Failed asserting that permission [{$relation}] was not granted to [{$user}] on [{$object}]";

        Assert::assertFalse(
            collect($this->tuples)->contains(function ($tuple) use ($user, $relation, $object) {
                return $tuple['user'] === $user
                    && $tuple['relation'] === $relation
                    && $tuple['object'] === $object;
            }),
            $message
        );
    }

    /**
     * Assert that a check was performed.
     */
    public function assertChecked(string $user, string $relation, string $object, ?string $message = null): void
    {
        $message = $message ?? "Failed asserting that check [{$relation}] was performed for [{$user}] on [{$object}]";

        Assert::assertTrue(
            collect($this->checks)->contains(function ($check) use ($user, $relation, $object) {
                return $check['user'] === $user
                    && $check['relation'] === $relation
                    && $check['object'] === $object;
            }),
            $message
        );
    }

    /**
     * Assert that a check was not performed.
     */
    public function assertNotChecked(string $user, string $relation, string $object, ?string $message = null): void
    {
        $message = $message ?? "Failed asserting that check [{$relation}] was not performed for [{$user}] on [{$object}]";

        Assert::assertFalse(
            collect($this->checks)->contains(function ($check) use ($user, $relation, $object) {
                return $check['user'] === $user
                    && $check['relation'] === $relation
                    && $check['object'] === $object;
            }),
            $message
        );
    }

    /**
     * Assert the number of checks performed.
     */
    public function assertCheckCount(int $count, ?string $message = null): void
    {
        $actual = count($this->checks);
        $message = $message ?? "Failed asserting that [{$count}] checks were performed. Actual: [{$actual}]";

        Assert::assertSame($count, $actual, $message);
    }

    /**
     * Assert that no checks were performed.
     */
    public function assertNoChecks(?string $message = null): void
    {
        $this->assertCheckCount(0, $message);
    }

    /**
     * Assert that a write batch was performed.
     */
    public function assertBatchWritten(?string $message = null): void
    {
        $message = $message ?? 'Failed asserting that a batch write was performed';

        Assert::assertNotEmpty($this->writes, $message);
    }

    /**
     * Assert that no batch writes were performed.
     */
    public function assertNoBatchWrites(?string $message = null): void
    {
        $message = $message ?? 'Failed asserting that no batch writes were performed';

        Assert::assertEmpty($this->writes, $message);
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
     * @return array<int, array{writes: array, deletes: array}>
     */
    public function getWrites(): array
    {
        return $this->writes;
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
     * Get all recorded expand calls.
     *
     * @return array<int, array{object: string, relation: string, result: array}>
     */
    public function getExpandCalls(): array
    {
        return $this->expandCalls;
    }
}