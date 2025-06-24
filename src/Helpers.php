<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\Contracts\{AuthorizationObject, AuthorizationType, AuthorizationUser, AuthorizationUserId};
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Query\AuthorizationQuery;

if (! function_exists('openfga_can')) {
    /**
     * Check if the current user has the given OpenFGA permission.
     *
     * @param string      $relation
     * @param mixed       $object
     * @param string|null $connection
     */
    function openfga_can(string $relation, $object, ?string $connection = null): bool
    {
        if (! Auth::check()) {
            return false;
        }

        /** @var OpenFgaManager $manager */
        $manager = app(OpenFgaManager::class);
        $user = Auth::user();

        if (null === $user) {
            return false;
        }

        $userId = openfga_resolve_user_id($user);
        $objectId = openfga_resolve_object($object);

        return $manager->check($userId, $relation, $objectId, [], [], $connection);
    }
}

if (! function_exists('openfga_cannot')) {
    /**
     * Check if the current user does NOT have the given OpenFGA permission.
     *
     * @param string      $relation
     * @param mixed       $object
     * @param string|null $connection
     */
    function openfga_cannot(string $relation, $object, ?string $connection = null): bool
    {
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
    function openfga_can_any(array $relations, $object, ?string $connection = null): bool
    {
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
    function openfga_can_all(array $relations, $object, ?string $connection = null): bool
    {
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
     */
    function openfga_grant($user, string $relation, $object, ?string $connection = null): void
    {
        /** @var OpenFgaManager $manager */
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
     */
    function openfga_revoke($user, string $relation, $object, ?string $connection = null): void
    {
        /** @var OpenFgaManager $manager */
        $manager = app(OpenFgaManager::class);

        $userId = openfga_resolve_user_id($user);
        $objectId = openfga_resolve_object($object);

        $manager->revoke($userId, $relation, $objectId, $connection);
    }
}

if (! function_exists('openfga_user')) {
    /**
     * Get the current user's OpenFGA identifier.
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
            return (string) $user->authorizationUser();
        }

        // User object with alternative method
        if (is_object($user) && method_exists($user, 'getAuthorizationUserId')) {
            /** @var AuthorizationUserId&object $user */
            return (string) $user->getAuthorizationUserId();
        }

        // Authenticatable user
        if (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
            /** @var Authenticatable $user */
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
     */
    function openfga_resolve_object($object): string
    {
        // String in object:id format
        if (is_string($object)) {
            return $object;
        }

        // Model with authorization support
        if (is_object($object) && method_exists($object, 'authorizationObject')) {
            /** @var AuthorizationObject&object $object */
            return (string) $object->authorizationObject();
        }

        // Model with authorization type method
        if (is_object($object) && method_exists($object, 'authorizationType') && method_exists($object, 'getKey')) {
            /** @var AuthorizationType&Model&object $object */
            $key = $object->getKey();

            if (is_string($key) || is_numeric($key)) {
                return (string) $object->authorizationType() . ':' . (string) $key;
            }

            throw new InvalidArgumentException('Model key must be string or numeric, got: ' . gettype($key));
        }

        // Eloquent model fallback
        if (is_object($object) && method_exists($object, 'getTable') && method_exists($object, 'getKey')) {
            /** @var Model $object */
            $key = $object->getKey();

            if (is_string($key) || is_numeric($key)) {
                return (string) $object->getTable() . ':' . (string) $key;
            }

            throw new InvalidArgumentException('Model key must be string or numeric, got: ' . gettype($key));
        }

        throw new InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($object));
    }
}

if (! function_exists('openfga_manager')) {
    /**
     * Get the OpenFGA manager instance.
     *
     * @param string|null $connection
     */
    function openfga_manager(?string $connection = null): OpenFgaManager
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
        return openfga_manager($connection)->query();
    }
}
