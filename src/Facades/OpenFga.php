<?php

declare(strict_types=1);

namespace OpenFga\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use OpenFga\Sdk\ClientInterface;

/**
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure check(string $user, string $relation, string $object, array $contextualTuples = [], array $context = [], ?string $authorizationModelId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure batchCheck(array $requests, ?string $authorizationModelId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure expand(string $relation, string $object, ?string $authorizationModelId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure listObjects(string $user, string $relation, string $type, array $contextualTuples = [], array $context = [], ?string $authorizationModelId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure listRelations(string $user, string $object, array $relations = [], array $contextualTuples = [], array $context = [], ?string $authorizationModelId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure listUsers(string $object, string $relation, array $userFilters = [], array $contextualTuples = [], array $context = [], ?string $authorizationModelId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure write(array $writes = [], array $deletes = [], ?string $authorizationModelId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure writeTuples(array $tuples, ?string $authorizationModelId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure deleteTuples(array $tuples, ?string $authorizationModelId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure readTuples(?string $user = null, ?string $relation = null, ?string $object = null, ?int $pageSize = null, ?string $continuationToken = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure readChanges(?string $type = null, ?int $pageSize = null, ?string $continuationToken = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure createStore(string $name, ?string $model = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure getStore(?string $storeId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure deleteStore(?string $storeId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure listStores(?int $pageSize = null, ?string $continuationToken = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure writeAuthorizationModel(array $typeDefinitions, array $schemaVersion = null, array $conditions = null, ?string $storeId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure readAuthorizationModel(?string $authorizationModelId = null, ?string $storeId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure readAuthorizationModels(?int $pageSize = null, ?string $continuationToken = null, ?string $storeId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure readLatestAuthorizationModel(?string $storeId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure writeAssertions(array $assertions, ?string $authorizationModelId = null, ?string $storeId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure readAssertions(?string $authorizationModelId = null, ?string $storeId = null)
 * @method static \OpenFga\Sdk\Result\Success|\OpenFga\Sdk\Result\Failure dsl(string $dsl, ?string $storeId = null)
 *
 * @see \OpenFga\Sdk\Client
 */
final class OpenFga extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return ClientInterface::class;
    }
}