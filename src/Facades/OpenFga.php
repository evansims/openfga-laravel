<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use OpenFGA\Client;
use Override;

/**
 * @api
 *
 * @method static bool                                              check(string $user, string $relation, string $object, array<int, mixed> $contextualTuples = [], array<string, mixed> $context = [], ?string $authorizationModelId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure batchCheck(array<int, mixed> $requests, ?string $authorizationModelId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure expand(string $relation, string $object, ?string $authorizationModelId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure listObjects(string $user, string $relation, string $type, array<int, mixed> $contextualTuples = [], array<string, mixed> $context = [], ?string $authorizationModelId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure listRelations(string $user, string $object, array<int, string> $relations = [], array<int, mixed> $contextualTuples = [], array<string, mixed> $context = [], ?string $authorizationModelId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure listUsers(string $object, string $relation, array<int, mixed> $userFilters = [], array<int, mixed> $contextualTuples = [], array<string, mixed> $context = [], ?string $authorizationModelId = null)
 * @method static bool                                              write(array<int, mixed> $writes = [], array<int, mixed> $deletes = [], ?string $authorizationModelId = null)
 * @method static bool                                              writeBatch(array<int, array{user: string, relation: string, object: string}> $writes = [], array<int, array{user: string, relation: string, object: string}> $deletes = [], ?string $connection = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure writeTuples(array<int, mixed> $tuples, ?string $authorizationModelId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure deleteTuples(array<int, mixed> $tuples, ?string $authorizationModelId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure readTuples(?string $user = null, ?string $relation = null, ?string $object = null, ?int $pageSize = null, ?string $continuationToken = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure readChanges(?string $type = null, ?int $pageSize = null, ?string $continuationToken = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure createStore(string $name, ?string $model = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure getStore(?string $storeId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure deleteStore(?string $storeId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure listStores(?int $pageSize = null, ?string $continuationToken = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure writeAuthorizationModel(array<int, mixed> $typeDefinitions, array<string, mixed> $schemaVersion = null, array<string, mixed> $conditions = null, ?string $storeId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure readAuthorizationModel(?string $authorizationModelId = null, ?string $storeId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure readAuthorizationModels(?int $pageSize = null, ?string $continuationToken = null, ?string $storeId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure readLatestAuthorizationModel(?string $storeId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure writeAssertions(array<int, mixed> $assertions, ?string $authorizationModelId = null, ?string $storeId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure readAssertions(?string $authorizationModelId = null, ?string $storeId = null)
 * @method static \OpenFGA\Results\Success|\OpenFGA\Results\Failure dsl(string $dsl, ?string $storeId = null)
 *
 * @see Client
 */
final class OpenFga extends Facade
{
    /**
     * Get the registered name of the component.
     */
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return 'openfga.manager';
    }
}
