<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Query;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Models\Collections\{TupleKeys};
use OpenFGA\Models\{TupleKey};
use ReflectionException;

use function assert;
use function is_array;

/**
 * Fluent query builder for constructing OpenFGA authorization queries.
 *
 * This builder provides an expressive, chainable API for checking permissions,
 * granting/revoking access, and querying relationships. It follows Laravel's
 * query builder pattern, making authorization code readable and intuitive.
 * Supports batch operations, contextual tuples, and all OpenFGA query types
 * while maintaining type safety and validation.
 *
 * Example: $allowed = OpenFga::query()->for('user:123')->can('read')->on('document:456')->check();
 *
 * @api
 */
final class AuthorizationQuery
{
    /**
     * Context object for the query.
     */
    private ?object $context = null;

    /**
     * Contextual tuples for the query.
     *
     * @var array<int, array{user: string, relation: string, object: string}>
     */
    private array $contextualTuples = [];

    /**
     * The object to check permissions on.
     */
    private ?string $object = null;

    /**
     * The relation to check.
     */
    private ?string $relation = null;

    /**
     * Relations to check for list operations.
     *
     * @var array<string>
     */
    private array $relations = [];

    /**
     * The object type for list operations.
     */
    private ?string $type = null;

    /**
     * The user to check permissions for.
     */
    private ?string $user = null;

    /**
     * User type filters for list operations.
     *
     * @var array<string>
     */
    private array $userTypes = [];

    /**
     * Create a new authorization query instance.
     *
     * @param OpenFgaManager $manager
     * @param ?string        $connection
     */
    public function __construct(
        private readonly OpenFgaManager $manager,
        private readonly ?string $connection = null,
    ) {
    }

    /**
     * Set the relation for the query.
     *
     * @param string $relation
     */
    public function can(string $relation): self
    {
        $this->relation = $relation;

        return $this;
    }

    /**
     * Execute a permission check.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function check(): bool
    {
        $this->validateCheckQuery();

        // After validation, we know these are not null
        assert(null !== $this->user);
        assert(null !== $this->relation);
        assert(null !== $this->object);

        /** @var array<string, mixed> $contextArray */
        $contextArray = null !== $this->context ? (array) $this->context : [];

        return $this->manager->check(
            $this->user,
            $this->relation,
            $this->object,
            $this->contextualTuples,
            $contextArray,
            $this->connection,
        );
    }

    /**
     * Execute a batch check for multiple permissions.
     *
     * @param array<int, array{user?: string, relation?: string, object?: string}> $checks
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     *
     * @return array<string, bool>
     */
    public function checkMany(array $checks): array
    {
        $batchChecks = [];

        foreach ($checks as $check) {
            $user = $check['user'] ?? $this->user;
            $relation = $check['relation'] ?? $this->relation;
            $object = $check['object'] ?? $this->object;

            if (null === $user || '' === $user || null === $relation || '' === $relation || null === $object || '' === $object) {
                throw new InvalidArgumentException('User, relation, and object are required for each check');
            }

            $batchChecks[] = [
                'user' => $user,
                'relation' => $relation,
                'object' => $object,
            ];
        }

        return $this->manager->batchCheck($batchChecks, $this->connection);
    }

    /**
     * Set the user for the query.
     *
     * @param string $user
     */
    public function for(string $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Clone the query with a clean state.
     */
    public function fresh(): self
    {
        return new self($this->manager, $this->connection);
    }

    /**
     * Grant permissions (write tuples).
     *
     * @param array<int, array{user?: string, relation?: string, object?: string}> $grants
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function grant(array $grants = []): bool
    {
        if ([] === $grants) {
            // Use the current query state
            $this->validateWriteQuery();

            // After validation, we know these are not null
            assert(null !== $this->user);
            assert(null !== $this->relation);
            assert(null !== $this->object);

            return $this->manager->grant($this->user, $this->relation, $this->object, $this->connection);
        }

        // Batch grant
        $tuples = new TupleKeys;

        foreach ($grants as $grant) {
            $user = $grant['user'] ?? $this->user;
            $relation = $grant['relation'] ?? $this->relation;
            $object = $grant['object'] ?? $this->object;

            if (null === $user || '' === $user || null === $relation || '' === $relation || null === $object || '' === $object) {
                throw new InvalidArgumentException('User, relation, and object are required for each grant');
            }

            $tuples->add(new TupleKey($user, $relation, $object));
        }

        return $this->manager->write($tuples, null, $this->connection);
    }

    /**
     * List objects the user has access to.
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     *
     * @return array<string>
     */
    public function listObjects(): array
    {
        if (null === $this->user) {
            throw new InvalidArgumentException('User is required for listObjects query');
        }

        if (null === $this->relation) {
            throw new InvalidArgumentException('Relation is required for listObjects query');
        }

        if (null === $this->type) {
            throw new InvalidArgumentException('Type is required for listObjects query');
        }

        /** @var array<string, mixed> $contextArray */
        $contextArray = null !== $this->context ? (array) $this->context : [];

        return $this->manager->listObjects(
            $this->user,
            $this->relation,
            $this->type,
            $this->contextualTuples,
            $contextArray,
            $this->connection,
        );
    }

    /**
     * List relations a user has on an object.
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     *
     * @return array<string, bool>
     */
    public function listRelations(): array
    {
        if (null === $this->user) {
            throw new InvalidArgumentException('User is required for listRelations query');
        }

        if (null === $this->object) {
            throw new InvalidArgumentException('Object is required for listRelations query');
        }

        /** @var array<string, mixed> $contextArray */
        $contextArray = null !== $this->context ? (array) $this->context : [];

        return $this->manager->listRelations(
            $this->user,
            $this->object,
            $this->relations,
            $this->contextualTuples,
            $contextArray,
            $this->connection,
        );
    }

    /**
     * List users who have access to an object.
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     *
     * @return array<mixed>
     */
    public function listUsers(): array
    {
        if (null === $this->object) {
            throw new InvalidArgumentException('Object is required for listUsers query');
        }

        if (null === $this->relation) {
            throw new InvalidArgumentException('Relation is required for listUsers query');
        }

        /** @var array<string, mixed> $contextArray */
        $contextArray = null !== $this->context ? (array) $this->context : [];

        return $this->manager->listUsers(
            $this->object,
            $this->relation,
            $this->userTypes,
            $this->contextualTuples,
            $contextArray,
            $this->connection,
        );
    }

    /**
     * Alias for the 'on' method.
     *
     * @param string $object
     */
    public function object(string $object): self
    {
        return $this->on($object);
    }

    /**
     * Set the object for the query.
     *
     * @param string $object
     */
    public function on(string $object): self
    {
        $this->object = $object;

        return $this;
    }

    /**
     * Alias for the 'can' method.
     *
     * @param string $relation
     */
    public function relation(string $relation): self
    {
        return $this->can($relation);
    }

    /**
     * Revoke permissions (delete tuples).
     *
     * @param array<int, array{user?: string, relation?: string, object?: string}> $revokes
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function revoke(array $revokes = []): bool
    {
        if ([] === $revokes) {
            // Use the current query state
            $this->validateWriteQuery();

            // After validation, we know these are not null
            assert(null !== $this->user);
            assert(null !== $this->relation);
            assert(null !== $this->object);

            return $this->manager->revoke($this->user, $this->relation, $this->object, $this->connection);
        }

        // Batch revoke
        $tuples = new TupleKeys;

        foreach ($revokes as $revoke) {
            $user = $revoke['user'] ?? $this->user;
            $relation = $revoke['relation'] ?? $this->relation;
            $object = $revoke['object'] ?? $this->object;

            if (null === $user || '' === $user || null === $relation || '' === $relation || null === $object || '' === $object) {
                throw new InvalidArgumentException('User, relation, and object are required for each revoke');
            }

            $tuples->add(new TupleKey($user, $relation, $object));
        }

        return $this->manager->write(null, $tuples, $this->connection);
    }

    /**
     * Set the object type for list operations.
     *
     * @param string $type
     */
    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Alias for the 'for' method.
     *
     * @param string $user
     */
    public function user(string $user): self
    {
        return $this->for($user);
    }

    /**
     * Filter by relations (for list operations).
     *
     * @param array<string>|string $relations
     */
    public function whereRelation(array | string $relations): self
    {
        $relations = is_array($relations) ? $relations : [$relations];
        $this->relations = array_merge($this->relations, $relations);

        return $this;
    }

    /**
     * Filter by user types (for list operations).
     *
     * @param array<string>|string $types
     */
    public function whereUserType(array | string $types): self
    {
        $types = is_array($types) ? $types : [$types];
        $this->userTypes = array_merge($this->userTypes, $types);

        return $this;
    }

    /**
     * Add contextual tuples to the query.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $tuples
     */
    public function withContext(array $tuples): self
    {
        $this->contextualTuples = array_merge($this->contextualTuples, $tuples);

        return $this;
    }

    /**
     * Set the context object.
     *
     * @param object $context
     */
    public function withContextObject(object $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Add a single contextual tuple.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     */
    public function withTuple(string $user, string $relation, string $object): self
    {
        $this->contextualTuples[] = [
            'user' => $user,
            'relation' => $relation,
            'object' => $object,
        ];

        return $this;
    }

    /**
     * Validate the query has required fields for check operations.
     *
     * @throws InvalidArgumentException
     */
    private function validateCheckQuery(): void
    {
        if (null === $this->user) {
            throw new InvalidArgumentException('User is required for check query');
        }

        if (null === $this->relation) {
            throw new InvalidArgumentException('Relation is required for check query');
        }

        if (null === $this->object) {
            throw new InvalidArgumentException('Object is required for check query');
        }
    }

    /**
     * Validate the query has required fields for write operations.
     *
     * @throws InvalidArgumentException
     */
    private function validateWriteQuery(): void
    {
        if (null === $this->user) {
            throw new InvalidArgumentException('User is required for write query');
        }

        if (null === $this->relation) {
            throw new InvalidArgumentException('Relation is required for write query');
        }

        if (null === $this->object) {
            throw new InvalidArgumentException('Object is required for write query');
        }
    }
}
