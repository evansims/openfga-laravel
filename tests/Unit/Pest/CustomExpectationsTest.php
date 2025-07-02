<?php

declare(strict_types=1);

use OpenFGA\Client;
use OpenFGA\Laravel\Batch\BatchResult;
use OpenFGA\Laravel\Tests\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

uses(TestCase::class);

// Import shared test helpers
use function OpenFGA\Laravel\Tests\Support\{
    createAuthObject,
    createAuthUser,
    createBatchWriteOperations,
    createPermissionTuple,
    createPermissionTuples,
    createTestAuthorizationModel,
    createTestModelId,
    createTestStoreId
};

describe('Custom Pest Expectations', function (): void {
    it('validates OpenFGA identifiers', function (string $identifier): void {
        expect($identifier)->toBeOpenFgaIdentifier();
    })->with([
        'user:123',
        'document:456',
        'organization:acme',
        'project:my-project_v2',
    ]);

    it('rejects invalid OpenFGA identifiers', function (string $invalidIdentifier): void {
        expect(static fn () => expect($invalidIdentifier)->toBeOpenFgaIdentifier())
            ->toThrow(ExpectationFailedException::class);
    })->with('invalid_identifiers');

    it('validates OpenFGA relations', function (string $relation): void {
        expect($relation)->toBeOpenFgaRelation();
    })->with('relations');

    it('validates permission tuples', function (): void {
        $tuple = createPermissionTuple(user: 'user:123', relation: 'owner', object: 'document:456');

        expect($tuple)->toBePermissionTuple();
    });

    it('validates authorization models', function (): void {
        $model = createTestAuthorizationModel();

        expect($model)->toBeAuthorizationModel();
    });

    it('validates batch results structure', function (): void {
        $result = new BatchResult(
            success: true,
            totalOperations: 10,
            processedOperations: 8,
            failedOperations: 2,
            duration: 1.5,
        );

        expect($result)->toBeBatchResult();
    });

    it('validates Laravel service registration', function (): void {
        expect($this->app)->toBeRegistered('openfga');
        expect($this->app)->toBeRegistered(Client::class);
    });

    it('validates Laravel configuration', function (): void {
        expect($this->app)->toHaveConfigKey('openfga.default');
        expect($this->app)->toHaveConfigKey('openfga.connections');
    });

    it('demonstrates helper functions', function (): void {
        $storeId = createTestStoreId();
        $modelId = createTestModelId();

        expect($storeId)->toBeString()->toStartWith('store_');
        expect($modelId)->toBeString()->toStartWith('model_');

        $user = createAuthUser('user:demo');
        expect($user->authorizationUser())->toBe('user:demo');

        $object = createAuthObject('document:demo');
        expect($object->authorizationObject())->toBe('document:demo');
    });

    it('demonstrates batch helpers', function (): void {
        $tuples = [
            ['user:123', 'owner', 'document:456'],
            ['user:456', 'editor', 'document:789'],
        ];

        $permissionTuples = createPermissionTuples($tuples);
        $writeOperations = createBatchWriteOperations($permissionTuples);

        expect($permissionTuples)->toHaveCount(2);
        expect($writeOperations)->toHaveCount(2);

        foreach ($permissionTuples as $permissionTuple) {
            expect($permissionTuple)->toBePermissionTuple();
        }
    });

    it('uses global datasets', function (string $user, string $object, string $relation): void {
        $tuple = createPermissionTuple(user: $user, relation: $relation, object: $object);

        expect($tuple)->toBePermissionTuple()
            ->and($tuple['user'])->toBe($user)
            ->and($tuple['relation'])->toBe($relation)
            ->and($tuple['object'])->toBe($object);
    })->with('users')->with('objects')->with('relations');
});
