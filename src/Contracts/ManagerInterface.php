<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Contracts;

use OpenFGA\Models\TupleKey;

/**
 * Core interface defining OpenFGA manager operations.
 *
 * This interface defines the essential operations for interacting with OpenFGA,
 * including permission checks, batch operations, and object listing. Implementations
 * provide connection management, caching strategies, and error handling while
 * maintaining a consistent API for authorization queries across your application.
 *
 * @api
 */
interface ManagerInterface
{
    /**
     * Batch check multiple permissions at once.
     *
     * @param  array<int, array{user: string, relation: string, object: string}> $checks
     * @param  ?string                                                           $connection
     * @return array<string, bool>
     */
    public function batchCheck(array $checks, ?string $connection = null): array;

    /**
     * Check if a user has a specific permission.
     *
     * @param string                                                                $user
     * @param string                                                                $relation
     * @param string                                                                $object
     * @param array<array{user: string, relation: string, object: string}|TupleKey> $contextualTuples
     * @param array<string, mixed>                                                  $context
     * @param ?string                                                               $connection
     */
    public function check(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): bool;

    /**
     * List objects that a user has a specific relation to.
     *
     * @param  string               $user
     * @param  string               $relation
     * @param  string               $type
     * @param  array<TupleKey>      $contextualTuples
     * @param  array<string, mixed> $context
     * @param  ?string              $connection
     * @return array<string>
     */
    public function listObjects(
        string $user,
        string $relation,
        string $type,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null,
    ): array;
}
