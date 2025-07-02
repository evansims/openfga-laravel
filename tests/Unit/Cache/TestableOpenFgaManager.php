<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Cache;

use Exception;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\OpenFgaClient;
use OpenFGA\Models\Collections\TupleKeysInterface;
use RuntimeException;

use function sprintf;

/**
 * Testable version of manager interface.
 */
final class TestableOpenFgaManager implements ManagerInterface
{
    private int $checkCount = 0;

    private array $checkResults = [];

    private int $listObjectsCount = 0;

    private array $listResults = [];

    private ?Exception $shouldThrow = null;

    public function batchCheck(array $checks, ?string $connection = null): array
    {
        $results = [];

        foreach ($checks as $index => $check) {
            [$user, $relation, $object] = $check;
            $key = sprintf('%s:%s:%s', $user, $relation, $object);
            $results[$index] = $this->checkResults[$key] ?? false;
        }

        return $results;
    }

    public function check(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): bool {
        ++$this->checkCount;

        if ($this->shouldThrow instanceof Exception) {
            throw $this->shouldThrow;
        }

        $key = sprintf('%s:%s:%s', $user, $relation, $object);

        return $this->checkResults[$key] ?? false;
    }

    public function connection(?string $name = null): OpenFgaClient
    {
        throw new RuntimeException('Not implemented');
    }

    public function expand(
        string $relation,
        string $object,
        ?string $connection = null,
    ): array {
        return [];
    }

    public function getCheckCount(): int
    {
        return $this->checkCount;
    }

    public function getConnections(): array
    {
        return [];
    }

    public function getDefaultConnection(): string
    {
        return 'default';
    }

    public function getListObjectsCount(): int
    {
        return $this->listObjectsCount;
    }

    public function grant(
        string | array $users,
        string $relation,
        string $object,
        ?string $connection = null,
    ): bool {
        return true;
    }

    public function health(?string $connection = null): bool
    {
        return true;
    }

    public function healthAll(): array
    {
        return ['default' => true];
    }

    public function listObjects(
        string $user,
        string $relation,
        string $type,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        ++$this->listObjectsCount;

        if ($this->shouldThrow instanceof Exception) {
            throw $this->shouldThrow;
        }

        $key = sprintf('%s:%s:%s', $user, $relation, $type);

        return $this->listResults[$key] ?? [];
    }

    public function listRelations(
        string $user,
        string $object,
        array $relations = [],
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        return [];
    }

    public function listUsers(
        string $object,
        string $relation,
        array $userTypes = [],
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        return [];
    }

    public function query(?string $connection = null): mixed
    {
        return null;
    }

    public function revoke(
        string | array $users,
        string $relation,
        string $object,
        ?string $connection = null,
    ): bool {
        return true;
    }

    public function setCheckResult(string $user, string $relation, string $object, bool $result): void
    {
        $this->checkResults[sprintf('%s:%s:%s', $user, $relation, $object)] = $result;
    }

    public function setListObjectsResult(string $user, string $relation, string $type, array $result): void
    {
        $this->listResults[sprintf('%s:%s:%s', $user, $relation, $type)] = $result;
    }

    public function setShouldThrow(?Exception $exception): void
    {
        $this->shouldThrow = $exception;
    }

    public function write(
        ?TupleKeysInterface $writes = null,
        ?TupleKeysInterface $deletes = null,
        ?string $connection = null,
    ): bool {
        return true;
    }
}
