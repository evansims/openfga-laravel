<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Services;

use OpenFGA\ClientInterface;
use OpenFGA\Laravel\Cache\ReadThroughCache;
use OpenFGA\Models\TupleKey;
use OpenFGA\Models\Collections\{BatchCheckItems, TupleKeys, UserTypeFilters};
use OpenFGA\Models\{BatchCheckItem, UserTypeFilter};
use OpenFGA\Results\{FailureInterface, SuccessInterface};

/**
 * Service class for OpenFGA authorization operations.
 *
 * This class contains the core business logic for authorization operations,
 * extracted from OpenFgaManager to allow for easier testing.
 */
class AuthorizationService
{
    /**
     * Create a new authorization service instance.
     */
    public function __construct(
        private ?ReadThroughCache $cache = null,
    ) {
    }

    /**
     * Perform a batch check operation.
     *
     * @param ClientInterface $client
     * @param array<int, array{user: string, relation: string, object: string}> $checks
     *
     * @return array<string, bool>
     */
    public function performBatchCheck(ClientInterface $client, array $checks): array
    {
        $items = new BatchCheckItems;

        foreach ($checks as $check) {
            $items->add(new BatchCheckItem($check['user'], $check['relation'], $check['object']));
        }

        $result = $client->batchCheck($items);

        if ($result instanceof FailureInterface) {
            throw new \RuntimeException($result->getError()->getMessage());
        }

        $results = [];

        if ($result instanceof SuccessInterface) {
            $responses = $result->getResult();

            foreach ($responses as $key => $response) {
                $results[$key] = $response->getAllowed();
            }
        }

        return $results;
    }

    /**
     * Check a single permission.
     *
     * @param ClientInterface $client
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param TupleKeys $contextualTuples
     * @param ?object $context
     *
     * @return bool
     */
    public function performCheck(
        ClientInterface $client,
        string $user,
        string $relation,
        string $object,
        TupleKeys $contextualTuples,
        ?object $context = null,
    ): bool {
        // Check cache first if available
        if ($this->cache !== null) {
            $cacheKey = $this->buildCheckCacheKey($user, $relation, $object, $contextualTuples, $context);
            $cached = $this->cache->get($cacheKey);
            
            if ($cached !== null) {
                return (bool) $cached;
            }
        }

        $result = $client->check($user, $relation, $object, $contextualTuples, $context);

        if ($result instanceof FailureInterface) {
            throw new \RuntimeException($result->getError()->getMessage());
        }

        $allowed = $result instanceof SuccessInterface && $result->getResult()->getAllowed();

        // Store in cache if available
        if ($this->cache !== null) {
            $ttl = $allowed ? 300 : 60; // Shorter TTL for negative results
            $this->cache->put($cacheKey, $allowed, $ttl);
        }

        return $allowed;
    }

    /**
     * List objects for a user with a relation.
     *
     * @param ClientInterface $client
     * @param string $user
     * @param string $relation
     * @param string $type
     * @param TupleKeys $contextualTuples
     * @param ?object $context
     *
     * @return array<string>
     */
    public function performListObjects(
        ClientInterface $client,
        string $user,
        string $relation,
        string $type,
        TupleKeys $contextualTuples,
        ?object $context = null,
    ): array {
        $result = $client->listObjects($user, $relation, $type, $contextualTuples, $context);

        if ($result instanceof FailureInterface) {
            throw new \RuntimeException($result->getError()->getMessage());
        }

        $objects = [];

        if ($result instanceof SuccessInterface) {
            foreach ($result->getResult()->getObjects() as $object) {
                $objects[] = $object;
            }
        }

        return $objects;
    }

    /**
     * List users with a relation to an object.
     *
     * @param ClientInterface $client
     * @param string $object
     * @param string $relation
     * @param array<string> $userTypes
     * @param TupleKeys $contextualTuples
     * @param ?object $context
     *
     * @return array<string>
     */
    public function performListUsers(
        ClientInterface $client,
        string $object,
        string $relation,
        array $userTypes,
        TupleKeys $contextualTuples,
        ?object $context = null,
    ): array {
        $userFilters = new UserTypeFilters;

        foreach ($userTypes as $type) {
            $userFilters->add(new UserTypeFilter($type));
        }

        $result = $client->listUsers($object, $relation, $userFilters, $contextualTuples, $context);

        if ($result instanceof FailureInterface) {
            throw new \RuntimeException($result->getError()->getMessage());
        }

        $users = [];

        if ($result instanceof SuccessInterface) {
            foreach ($result->getResult()->getUsers() as $user) {
                $users[] = (string) $user;
            }
        }

        return $users;
    }

    /**
     * Write tuples (grant/revoke permissions).
     *
     * @param ClientInterface $client
     * @param ?TupleKeys $writes
     * @param ?TupleKeys $deletes
     *
     * @return bool
     */
    public function performWrite(
        ClientInterface $client,
        ?TupleKeys $writes = null,
        ?TupleKeys $deletes = null,
    ): bool {
        // Invalidate cache if available
        if ($this->cache !== null) {
            $this->invalidateCache($writes, $deletes);
        }

        $result = $client->write($writes, $deletes);

        if ($result instanceof FailureInterface) {
            throw new \RuntimeException($result->getError()->getMessage());
        }

        return $result instanceof SuccessInterface;
    }

    /**
     * Normalize contextual tuples.
     *
     * @param array<array{user: string, relation: string, object: string}|TupleKey> $contextualTuples
     *
     * @return TupleKeys
     */
    public function normalizeContextualTuples(array $contextualTuples): TupleKeys
    {
        $tuples = new TupleKeys;

        foreach ($contextualTuples as $tuple) {
            if ($tuple instanceof TupleKey) {
                $tuples->add($tuple);
            } elseif (is_array($tuple)) {
                $tuples->add(new TupleKey(
                    $tuple['user'] ?? '',
                    $tuple['relation'] ?? '',
                    $tuple['object'] ?? '',
                ));
            }
        }

        return $tuples;
    }

    /**
     * Build cache key for check operation.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     * @param TupleKeys $contextualTuples
     * @param ?object $context
     *
     * @return string
     */
    private function buildCheckCacheKey(
        string $user,
        string $relation,
        string $object,
        TupleKeys $contextualTuples,
        ?object $context,
    ): string {
        $parts = [
            'check',
            $user,
            $relation,
            $object,
        ];

        if (count($contextualTuples) > 0) {
            $tupleStrings = [];
            foreach ($contextualTuples as $tuple) {
                $tupleStrings[] = sprintf('%s:%s:%s', $tuple->getUser(), $tuple->getRelation(), $tuple->getObject());
            }
            $parts[] = implode('|', $tupleStrings);
        }

        if ($context !== null) {
            $parts[] = md5(serialize($context));
        }

        return implode(':', $parts);
    }

    /**
     * Invalidate cache entries for written/deleted tuples.
     *
     * @param ?TupleKeys $writes
     * @param ?TupleKeys $deletes
     *
     * @return void
     */
    private function invalidateCache(?TupleKeys $writes, ?TupleKeys $deletes): void
    {
        if ($this->cache === null) {
            return;
        }

        $patterns = [];

        if ($writes !== null) {
            foreach ($writes as $tuple) {
                // Invalidate patterns that might be affected
                $patterns[] = sprintf('check:*:%s:%s', $tuple->getRelation(), $tuple->getObject());
                $patterns[] = sprintf('check:%s:*:%s', $tuple->getUser(), $tuple->getObject());
            }
        }

        if ($deletes !== null) {
            foreach ($deletes as $tuple) {
                // Invalidate patterns that might be affected
                $patterns[] = sprintf('check:*:%s:%s', $tuple->getRelation(), $tuple->getObject());
                $patterns[] = sprintf('check:%s:*:%s', $tuple->getUser(), $tuple->getObject());
            }
        }

        // Note: Actual cache invalidation would depend on cache implementation
        // This is a simplified version
        foreach ($patterns as $pattern) {
            // Cache implementation would handle pattern-based invalidation
        }
    }
}