<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Query;

use InvalidArgumentException;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Models\TupleKey;
use OpenFGA\Models\UserTypeFilter;
use OpenFGA\Models\Collections\TupleKeys;
use OpenFGA\Models\Collections\UserTypeFilters;

/**
 * Provides a fluent query builder interface for OpenFGA operations.
 */
final class AuthorizationQuery
{
    /**
     * The user to check permissions for.
     */
    private ?string $user = null;

    /**
     * The relation to check.
     */
    private ?string $relation = null;

    /**
     * The object to check permissions on.
     */
    private ?string $object = null;

    /**
     * The object type for list operations.
     */
    private ?string $type = null;

    /**
     * Contextual tuples for the query.
     *
     * @var array<int, array{user: string, relation: string, object: string}>
     */
    private array $contextualTuples = [];

    /**
     * Context object for the query.
     */
    private ?object $context = null;

    /**
     * User type filters for list operations.
     *
     * @var array<string>
     */
    private array $userTypes = [];

    /**
     * Relations to check for list operations.
     *
     * @var array<string>
     */
    private array $relations = [];

    /**
     * Create a new authorization query instance.
     */
    public function __construct(
        private readonly OpenFgaManager $manager,
        private readonly ?string $connection = null,
    ) {
    }

    /**
     * Set the user for the query.
     */
    public function for(string $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Alias for the 'for' method.
     */
    public function user(string $user): self
    {
        return $this->for($user);
    }

    /**
     * Set the relation for the query.
     */
    public function can(string $relation): self
    {
        $this->relation = $relation;

        return $this;
    }

    /**
     * Alias for the 'can' method.
     */
    public function relation(string $relation): self
    {
        return $this->can($relation);
    }

    /**
     * Set the object for the query.
     */
    public function on(string $object): self
    {
        $this->object = $object;

        return $this;
    }

    /**
     * Alias for the 'on' method.
     */
    public function object(string $object): self
    {
        return $this->on($object);
    }

    /**
     * Set the object type for list operations.
     */
    public function type(string $type): self
    {
        $this->type = $type;

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
     * Add a single contextual tuple.
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
     * Set the context object.
     */
    public function withContextObject(object $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Filter by user types (for list operations).
     *
     * @param array<string>|string $types
     */
    public function whereUserType(array|string $types): self
    {
        $types = is_array($types) ? $types : [$types];
        $this->userTypes = array_merge($this->userTypes, $types);

        return $this;
    }

    /**
     * Filter by relations (for list operations).
     *
     * @param array<string>|string $relations
     */
    public function whereRelation(array|string $relations): self
    {
        $relations = is_array($relations) ? $relations : [$relations];
        $this->relations = array_merge($this->relations, $relations);

        return $this;
    }

    /**
     * Execute a permission check.
     *
     * @throws InvalidArgumentException
     * @throws \OpenFGA\Exceptions\ClientThrowable
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Exception
     */
    public function check(): bool
    {
        $this->validateCheckQuery();
        
        // After validation, we know these are not null
        assert($this->user !== null);
        assert($this->relation !== null);
        assert($this->object !== null);

        return $this->manager->check(
            $this->user,
            $this->relation,
            $this->object,
            $this->contextualTuples,
            $this->context,
            $this->connection
        );
    }

    /**
     * Execute a batch check for multiple permissions.
     *
     * @param array<int, array{user?: string, relation?: string, object?: string}> $checks
     *
     * @throws \OpenFGA\Exceptions\ClientThrowable
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws InvalidArgumentException
     * @throws \Exception
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
            
            if ($user === null || $user === '' || $relation === null || $relation === '' || $object === null || $object === '') {
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
     * List objects the user has access to.
     *
     * @throws InvalidArgumentException
     * @throws \OpenFGA\Exceptions\ClientThrowable
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Exception
     *
     * @return array<string>
     */
    public function listObjects(): array
    {
        if ($this->user === null) {
            throw new InvalidArgumentException('User is required for listObjects query');
        }

        if ($this->relation === null) {
            throw new InvalidArgumentException('Relation is required for listObjects query');
        }

        if ($this->type === null) {
            throw new InvalidArgumentException('Type is required for listObjects query');
        }

        return $this->manager->listObjects(
            $this->user,
            $this->relation,
            $this->type,
            $this->contextualTuples,
            $this->context,
            $this->connection
        );
    }

    /**
     * List users who have access to an object.
     *
     * @throws InvalidArgumentException
     * @throws \OpenFGA\Exceptions\ClientThrowable
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Exception
     *
     * @return array<mixed>
     */
    public function listUsers(): array
    {
        if ($this->object === null) {
            throw new InvalidArgumentException('Object is required for listUsers query');
        }

        if ($this->relation === null) {
            throw new InvalidArgumentException('Relation is required for listUsers query');
        }

        return $this->manager->listUsers(
            $this->object,
            $this->relation,
            $this->userTypes,
            $this->contextualTuples,
            $this->context,
            $this->connection
        );
    }

    /**
     * List relations a user has on an object.
     *
     * @throws InvalidArgumentException
     * @throws \OpenFGA\Exceptions\ClientThrowable
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Exception
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *
     * @return array<string, bool>
     */
    public function listRelations(): array
    {
        if ($this->user === null) {
            throw new InvalidArgumentException('User is required for listRelations query');
        }

        if ($this->object === null) {
            throw new InvalidArgumentException('Object is required for listRelations query');
        }

        return $this->manager->listRelations(
            $this->user,
            $this->object,
            $this->relations,
            $this->contextualTuples,
            $this->context,
            $this->connection
        );
    }

    /**
     * Grant permissions (write tuples).
     *
     * @param array<int, array{user?: string, relation?: string, object?: string}> $grants
     *
     * @throws InvalidArgumentException
     * @throws \OpenFGA\Exceptions\ClientThrowable
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Exception
     * @throws \ReflectionException
     */
    public function grant(array $grants = []): bool
    {
        if (count($grants) === 0) {
            // Use the current query state
            $this->validateWriteQuery();
            
            // After validation, we know these are not null
            assert($this->user !== null);
            assert($this->relation !== null);
            assert($this->object !== null);
            
            return $this->manager->grant($this->user, $this->relation, $this->object, $this->connection);
        }
        
        // Batch grant
        $tuples = new TupleKeys();
        foreach ($grants as $grant) {
            $user = $grant['user'] ?? $this->user;
            $relation = $grant['relation'] ?? $this->relation;
            $object = $grant['object'] ?? $this->object;
            
            if ($user === null || $user === '' || $relation === null || $relation === '' || $object === null || $object === '') {
                throw new InvalidArgumentException('User, relation, and object are required for each grant');
            }
            
            $tuples->add(new TupleKey($user, $relation, $object));
        }

        return $this->manager->write($tuples, null, $this->connection);
    }

    /**
     * Revoke permissions (delete tuples).
     *
     * @param array<int, array{user?: string, relation?: string, object?: string}> $revokes
     *
     * @throws InvalidArgumentException
     * @throws \OpenFGA\Exceptions\ClientThrowable
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Exception
     * @throws \ReflectionException
     */
    public function revoke(array $revokes = []): bool
    {
        if (count($revokes) === 0) {
            // Use the current query state
            $this->validateWriteQuery();
            
            // After validation, we know these are not null
            assert($this->user !== null);
            assert($this->relation !== null);
            assert($this->object !== null);
            
            return $this->manager->revoke($this->user, $this->relation, $this->object, $this->connection);
        }
        
        // Batch revoke
        $tuples = new TupleKeys();
        foreach ($revokes as $revoke) {
            $user = $revoke['user'] ?? $this->user;
            $relation = $revoke['relation'] ?? $this->relation;
            $object = $revoke['object'] ?? $this->object;
            
            if ($user === null || $user === '' || $relation === null || $relation === '' || $object === null || $object === '') {
                throw new InvalidArgumentException('User, relation, and object are required for each revoke');
            }
            
            $tuples->add(new TupleKey($user, $relation, $object));
        }

        return $this->manager->write(null, $tuples, $this->connection);
    }

    /**
     * Clone the query with a clean state.
     */
    public function fresh(): self
    {
        return new self($this->manager, $this->connection);
    }

    /**
     * Validate the query has required fields for check operations.
     *
     * @throws InvalidArgumentException
     */
    private function validateCheckQuery(): void
    {
        if ($this->user === null) {
            throw new InvalidArgumentException('User is required for check query');
        }

        if ($this->relation === null) {
            throw new InvalidArgumentException('Relation is required for check query');
        }

        if ($this->object === null) {
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
        if ($this->user === null) {
            throw new InvalidArgumentException('User is required for write query');
        }

        if ($this->relation === null) {
            throw new InvalidArgumentException('Relation is required for write query');
        }

        if ($this->object === null) {
            throw new InvalidArgumentException('Object is required for write query');
        }
    }
}