<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use OpenFGA\ClientInterface;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Query\AuthorizationQuery;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

use function func_get_args;

/**
 * Test double for OpenFgaManager to work around final class limitation.
 */
final class TestOpenFgaManager extends OpenFgaManager
{
    public $checkCalls = [];

    public $grantCalls = [];

    public $listObjectsCalls = [];

    public $listRelationsCalls = [];

    public $listUsersCalls = [];

    public $queryCalls = [];

    public $revokeCalls = [];

    protected $checkResults = [];

    protected $grantResults = [];

    protected $listObjectsResults = [];

    protected $listRelationsResults = [];

    protected $listUsersResults = [];

    protected $queryResult = null;

    protected $revokeResults = [];

    public function __construct()
    {
        // Skip parent constructor
    }

    public function assertCheckCalled(string $user, string $relation, string $object): void
    {
        foreach ($this->checkCalls as $call) {
            if ($call[0] === $user && $call[1] === $relation && $call[2] === $object) {
                return;
            }
        }

        throw new AssertionFailedError(message: "Expected check('{$user}', '{$relation}', '{$object}') to be called");
    }

    public function assertGrantCalled(string $user, string $relation, string $object): void
    {
        foreach ($this->grantCalls as $call) {
            if ($call[0] === $user && $call[1] === $relation && $call[2] === $object) {
                return;
            }
        }

        throw new AssertionFailedError(message: "Expected grant('{$user}', '{$relation}', '{$object}') to be called");
    }

    public function assertRevokeCalled(string $user, string $relation, string $object): void
    {
        foreach ($this->revokeCalls as $call) {
            if ($call[0] === $user && $call[1] === $relation && $call[2] === $object) {
                return;
            }
        }

        throw new AssertionFailedError(message: "Expected revoke('{$user}', '{$relation}', '{$object}') to be called");
    }

    public function check(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): bool {
        $this->checkCalls[] = func_get_args();

        foreach ($this->checkResults as $expected) {
            if ($expected['args'] === [$user, $relation, $object]) {
                return $expected['result'];
            }
        }

        return false;
    }

    public function connection(?string $name = null): ClientInterface
    {
        throw new RuntimeException('Not implemented for testing');
    }

    public function grant(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        ?string $connection = null,
    ): bool {
        $this->grantCalls[] = func_get_args();

        foreach ($this->grantResults as $expected) {
            if ($expected['args'] === [$user, $relation, $object]) {
                return $expected['result'];
            }
        }

        return false;
    }

    public function listObjects(
        string $user,
        string $relation,
        string $type,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        $this->listObjectsCalls[] = func_get_args();

        foreach ($this->listObjectsResults as $expected) {
            if ($expected['args'] === [$user, $relation, $type]) {
                return $expected['result'];
            }
        }

        return [];
    }

    public function listRelations(
        string $user,
        string $object,
        array $relations = [],
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        $this->listRelationsCalls[] = func_get_args();

        foreach ($this->listRelationsResults as $expected) {
            if ($expected['args'] === [$user, $object, $relations]) {
                return $expected['result'];
            }
        }

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
        $this->listUsersCalls[] = func_get_args();

        foreach ($this->listUsersResults as $expected) {
            if ($expected['args'] === [$object, $relation, $userTypes]) {
                return $expected['result'];
            }
        }

        return [];
    }

    public function query(?string $connection = null): AuthorizationQuery
    {
        $this->queryCalls[] = func_get_args();

        if (null !== $this->queryResult) {
            return $this->queryResult;
        }

        throw new RuntimeException('No query result configured');
    }

    public function revoke(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        ?string $connection = null,
    ): bool {
        $this->revokeCalls[] = func_get_args();

        foreach ($this->revokeResults as $expected) {
            if ($expected['args'] === [$user, $relation, $object]) {
                return $expected['result'];
            }
        }

        return false;
    }

    public function shouldReceiveCheck(string $user, string $relation, string $object, bool $result): self
    {
        $this->checkResults[] = [
            'args' => [$user, $relation, $object],
            'result' => $result,
        ];

        return $this;
    }

    public function shouldReceiveGrant(string $user, string $relation, string $object, bool $result): self
    {
        $this->grantResults[] = [
            'args' => [$user, $relation, $object],
            'result' => $result,
        ];

        return $this;
    }

    public function shouldReceiveListObjects(string $user, string $relation, string $type, array $result): self
    {
        $this->listObjectsResults[] = [
            'args' => [$user, $relation, $type],
            'result' => $result,
        ];

        return $this;
    }

    public function shouldReceiveListRelations(string $user, string $object, array $relations, array $result): self
    {
        $this->listRelationsResults[] = [
            'args' => [$user, $object, $relations],
            'result' => $result,
        ];

        return $this;
    }

    public function shouldReceiveListUsers(string $object, string $relation, array $userTypes, array $result): self
    {
        $this->listUsersResults[] = [
            'args' => [$object, $relation, $userTypes],
            'result' => $result,
        ];

        return $this;
    }

    public function shouldReceiveQuery($queryResult): self
    {
        $this->queryResult = $queryResult;

        return $this;
    }

    public function shouldReceiveRevoke(string $user, string $relation, string $object, bool $result): self
    {
        $this->revokeResults[] = [
            'args' => [$user, $relation, $object],
            'result' => $result,
        ];

        return $this;
    }
}
