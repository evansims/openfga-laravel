<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use OpenFGA\ClientInterface;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Query\AuthorizationQuery;
use Override;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

use function func_get_args;
use function sprintf;

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

    private array $checkResults = [];

    private array $grantResults = [];

    private array $listObjectsResults = [];

    private array $listRelationsResults = [];

    private array $listUsersResults = [];

    private $queryResult;

    private array $revokeResults = [];

    public function __construct()
    {
        // Skip parent constructor
    }

    public function assertCheckCalled(string $user, string $relation, string $object): void
    {
        foreach ($this->checkCalls as $checkCall) {
            if ($checkCall[0] === $user && $checkCall[1] === $relation && $checkCall[2] === $object) {
                return;
            }
        }

        throw new AssertionFailedError(message: sprintf("Expected check('%s', '%s', '%s') to be called", $user, $relation, $object));
    }

    public function assertGrantCalled(string $user, string $relation, string $object): void
    {
        foreach ($this->grantCalls as $grantCall) {
            if ($grantCall[0] === $user && $grantCall[1] === $relation && $grantCall[2] === $object) {
                return;
            }
        }

        throw new AssertionFailedError(message: sprintf("Expected grant('%s', '%s', '%s') to be called", $user, $relation, $object));
    }

    public function assertRevokeCalled(string $user, string $relation, string $object): void
    {
        foreach ($this->revokeCalls as $revokeCall) {
            if ($revokeCall[0] === $user && $revokeCall[1] === $relation && $revokeCall[2] === $object) {
                return;
            }
        }

        throw new AssertionFailedError(message: sprintf("Expected revoke('%s', '%s', '%s') to be called", $user, $relation, $object));
    }

    #[Override]
    public function check(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): bool {
        $this->checkCalls[] = func_get_args();

        foreach ($this->checkResults as $checkResult) {
            if ($checkResult['args'] === [$user, $relation, $object]) {
                return $checkResult['result'];
            }
        }

        return false;
    }

    #[Override]
    public function connection(?string $name = null): ClientInterface
    {
        throw new RuntimeException('Not implemented for testing');
    }

    #[Override]
    public function grant(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        ?string $connection = null,
    ): bool {
        $this->grantCalls[] = func_get_args();

        foreach ($this->grantResults as $grantResult) {
            if ($grantResult['args'] === [$user, $relation, $object]) {
                return $grantResult['result'];
            }
        }

        return false;
    }

    #[Override]
    public function listObjects(
        string $user,
        string $relation,
        string $type,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        $this->listObjectsCalls[] = func_get_args();

        foreach ($this->listObjectsResults as $listObjectResult) {
            if ($listObjectResult['args'] === [$user, $relation, $type]) {
                return $listObjectResult['result'];
            }
        }

        return [];
    }

    #[Override]
    public function listRelations(
        string $user,
        string $object,
        array $relations = [],
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        $this->listRelationsCalls[] = func_get_args();

        foreach ($this->listRelationsResults as $listRelationResult) {
            if ($listRelationResult['args'] === [$user, $object, $relations]) {
                return $listRelationResult['result'];
            }
        }

        return [];
    }

    #[Override]
    public function listUsers(
        string $object,
        string $relation,
        array $userTypes = [],
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array {
        $this->listUsersCalls[] = func_get_args();

        foreach ($this->listUsersResults as $listUserResult) {
            if ($listUserResult['args'] === [$object, $relation, $userTypes]) {
                return $listUserResult['result'];
            }
        }

        return [];
    }

    #[Override]
    public function query(?string $connection = null): AuthorizationQuery
    {
        $this->queryCalls[] = func_get_args();

        if (null !== $this->queryResult) {
            return $this->queryResult;
        }

        throw new RuntimeException('No query result configured');
    }

    #[Override]
    public function revoke(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        ?string $connection = null,
    ): bool {
        $this->revokeCalls[] = func_get_args();

        foreach ($this->revokeResults as $revokeResult) {
            if ($revokeResult['args'] === [$user, $relation, $object]) {
                return $revokeResult['result'];
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
