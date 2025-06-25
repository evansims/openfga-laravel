<?php

declare(strict_types=1);

namespace OpenFGA\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Contracts\{
    AuthorizationObject,
    AuthorizationType,
    AuthorizationUser,
    AuthorizationUserId
};
use OpenFGA\Laravel\Helpers\ModelKeyHelper;
use OpenFGA\Laravel\Query\AuthorizationQuery;

use function function_exists;
use function gettype;
use function is_object;
use function is_string;

if (! function_exists('openfga_can')) {
    /**
     * Check if the current user has the given OpenFGA permission.
     *
     * @param string      $relation
     * @param mixed       $object
     * @param string|null $connection
     *
     * @throws Psr\SimpleCache\InvalidArgumentException
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    function openfga_can(
        string $relation,
        $object,
        ?string $connection = null,
    ): bool {
        if (! Auth::check()) {
            return false;
        }

        $manager = app(OpenFgaManager::class);
        $user = Auth::user();

        if (null === $user) {
            return false;
        }

        $userId = openfga_resolve_user_id($user);
        $objectId = openfga_resolve_object($object);

        return $manager->check(
            $userId,
            $relation,
            $objectId,
            [],
            [],
            $connection,
        );
    }
}

if (! function_exists('openfga_cannot')) {
    /**
     * Check if the current user does NOT have the given OpenFGA permission.
     *
     * @param string      $relation
     * @param mixed       $object
     * @param string|null $connection
     *
     * @throws Psr\SimpleCache\InvalidArgumentException
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    function openfga_cannot(
        string $relation,
        $object,
        ?string $connection = null,
    ): bool {
        return ! openfga_can($relation, $object, $connection);
    }
}

if (! function_exists('openfga_can_any')) {
    /**
     * Check if the current user has any of the given OpenFGA permissions.
     *
     * @param array<string> $relations
     * @param mixed         $object
     * @param string|null   $connection
     */
    function openfga_can_any(
        array $relations,
        $object,
        ?string $connection = null,
    ): bool {
        foreach ($relations as $relation) {
            if (openfga_can($relation, $object, $connection)) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('openfga_can_all')) {
    /**
     * Check if the current user has all of the given OpenFGA permissions.
     *
     * @param array<string> $relations
     * @param mixed         $object
     * @param string|null   $connection
     */
    function openfga_can_all(
        array $relations,
        $object,
        ?string $connection = null,
    ): bool {
        foreach ($relations as $relation) {
            if (! openfga_can($relation, $object, $connection)) {
                return false;
            }
        }

        return true;
    }
}

if (! function_exists('openfga_grant')) {
    /**
     * Grant a permission to a user.
     *
     * @param mixed|string $user
     * @param string       $relation
     * @param mixed        $object
     * @param string|null  $connection
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    function openfga_grant(
        $user,
        string $relation,
        $object,
        ?string $connection = null,
    ): void {
        $manager = app(OpenFgaManager::class);

        $userId = openfga_resolve_user_id($user);
        $objectId = openfga_resolve_object($object);

        $manager->grant($userId, $relation, $objectId, $connection);
    }
}

if (! function_exists('openfga_revoke')) {
    /**
     * Revoke a permission from a user.
     *
     * @param mixed|string $user
     * @param string       $relation
     * @param mixed        $object
     * @param string|null  $connection
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    function openfga_revoke(
        $user,
        string $relation,
        $object,
        ?string $connection = null,
    ): void {
        $manager = app(OpenFgaManager::class);

        $userId = openfga_resolve_user_id($user);
        $objectId = openfga_resolve_object($object);

        $manager->revoke($userId, $relation, $objectId, $connection);
    }
}

if (! function_exists('openfga_user')) {
    /**
     * Get the current user's OpenFGA identifier.
     *
     * @throws InvalidArgumentException
     */
    function openfga_user(): ?string
    {
        if (! Auth::check()) {
            return null;
        }

        return openfga_resolve_user_id(Auth::user());
    }
}

if (! function_exists('openfga_resolve_user_id')) {
    /**
     * Resolve a user identifier for OpenFGA.
     *
     * @param mixed $user
     *
     * @throws InvalidArgumentException
     */
    function openfga_resolve_user_id($user): string
    {
        // String identifier
        if (is_string($user)) {
            return $user;
        }

        // Numeric identifier
        if (is_numeric($user)) {
            return 'user:' . (string) $user;
        }

        // User object with custom method
        if (is_object($user) && method_exists($user, 'authorizationUser')) {
            /** @var AuthorizationUser&object $user */
            return $user->authorizationUser();
        }

        // User object with alternative method
        if (
            is_object($user)
            && method_exists($user, 'getAuthorizationUserId')
        ) {
            /** @var AuthorizationUserId&object $user */
            return $user->getAuthorizationUserId();
        }

        // Authenticatable user
        if (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
            /** @var Authenticatable $user */
            /** @var mixed $identifier */
            $identifier = $user->getAuthIdentifier();

            if (is_string($identifier) || is_numeric($identifier)) {
                return 'user:' . (string) $identifier;
            }

            throw new InvalidArgumentException('User identifier must be string or numeric, got: ' . gettype($identifier));
        }

        throw new InvalidArgumentException('Cannot resolve user identifier for: ' . gettype($user));
    }
}

if (! function_exists('openfga_resolve_object')) {
    /**
     * Resolve an object identifier for OpenFGA.
     *
     * @param mixed $object
     *
     * @throws InvalidArgumentException
     */
    function openfga_resolve_object($object): string
    {
        // String in object:id format
        if (is_string($object)) {
            return $object;
        }

        // Model with authorization support
        if (
            is_object($object)
            && method_exists($object, 'authorizationObject')
        ) {
            /** @var AuthorizationObject&object $object */
            return $object->authorizationObject();
        }

        // Model with authorization type method
        if (
            is_object($object)
            && method_exists($object, 'authorizationType')
            && method_exists($object, 'getKey')
        ) {
            /** @var AuthorizationType&Model&object $object */
            $key = ModelKeyHelper::stringId($object);
            $type = $object->authorizationType();

            return $type . ':' . $key;
        }

        // Eloquent model fallback
        // Check is_object first to satisfy Psalm's type checking
        if (is_object($object)) {
            /** @var object $object */
            if (
                method_exists($object, 'getTable')
                && method_exists($object, 'getKey')
            ) {
                /** @var Model $object */
                $key = ModelKeyHelper::stringId($object);
                $table = $object->getTable();

                return $table . ':' . $key;
            }
        }

        throw new InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($object));
    }
}

if (! function_exists('openfga_manager')) {
    /**
     * Get the OpenFGA manager instance.
     */
    function openfga_manager(): OpenFgaManager
    {
        return app(OpenFgaManager::class);
        // connection() returns ClientInterface, not OpenFgaManager
        // So we just return the manager itself, which can use a specific connection via its methods
    }
}

if (! function_exists('openfga_query')) {
    /**
     * Create a new OpenFGA query builder.
     *
     * @param string|null $connection
     */
    function openfga_query(?string $connection = null): AuthorizationQuery
    {
        return openfga_manager()->query($connection);
    }
}
