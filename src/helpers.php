<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\OpenFgaManager;

if (!function_exists('openfga_can')) {
    /**
     * Check if the current user has the given OpenFGA permission.
     *
     * @param string      $relation
     * @param mixed       $object
     * @param string|null $connection
     *
     * @return bool
     */
    function openfga_can(string $relation, $object, ?string $connection = null): bool
    {
        if (!Auth::check()) {
            return false;
        }

        $manager = app(OpenFgaManager::class);
        $user = Auth::user();
        
        $userId = openfga_resolve_user_id($user);
        $objectId = openfga_resolve_object($object);

        return $manager->connection($connection)->check($userId, $relation, $objectId);
    }
}

if (!function_exists('openfga_cannot')) {
    /**
     * Check if the current user does NOT have the given OpenFGA permission.
     *
     * @param string      $relation
     * @param mixed       $object
     * @param string|null $connection
     *
     * @return bool
     */
    function openfga_cannot(string $relation, $object, ?string $connection = null): bool
    {
        return !openfga_can($relation, $object, $connection);
    }
}

if (!function_exists('openfga_can_any')) {
    /**
     * Check if the current user has any of the given OpenFGA permissions.
     *
     * @param array<string> $relations
     * @param mixed         $object
     * @param string|null   $connection
     *
     * @return bool
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

if (!function_exists('openfga_can_all')) {
    /**
     * Check if the current user has all of the given OpenFGA permissions.
     *
     * @param array<string> $relations
     * @param mixed         $object
     * @param string|null   $connection
     *
     * @return bool
     */
    function openfga_can_all(array $relations, $object, ?string $connection = null): bool
    {
        foreach ($relations as $relation) {
            if (!openfga_can($relation, $object, $connection)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('openfga_grant')) {
    /**
     * Grant a permission to a user.
     *
     * @param string|mixed $user
     * @param string       $relation
     * @param mixed        $object
     * @param string|null  $connection
     *
     * @return void
     */
    function openfga_grant($user, string $relation, $object, ?string $connection = null): void
    {
        $manager = app(OpenFgaManager::class);
        
        $userId = openfga_resolve_user_id($user);
        $objectId = openfga_resolve_object($object);

        $manager->connection($connection)->grant($userId, $relation, $objectId);
    }
}

if (!function_exists('openfga_revoke')) {
    /**
     * Revoke a permission from a user.
     *
     * @param string|mixed $user
     * @param string       $relation
     * @param mixed        $object
     * @param string|null  $connection
     *
     * @return void
     */
    function openfga_revoke($user, string $relation, $object, ?string $connection = null): void
    {
        $manager = app(OpenFgaManager::class);
        
        $userId = openfga_resolve_user_id($user);
        $objectId = openfga_resolve_object($object);

        $manager->connection($connection)->revoke($userId, $relation, $objectId);
    }
}

if (!function_exists('openfga_user')) {
    /**
     * Get the current user's OpenFGA identifier.
     *
     * @return string|null
     */
    function openfga_user(): ?string
    {
        if (!Auth::check()) {
            return null;
        }

        return openfga_resolve_user_id(Auth::user());
    }
}

if (!function_exists('openfga_resolve_user_id')) {
    /**
     * Resolve a user identifier for OpenFGA.
     *
     * @param mixed $user
     *
     * @return string
     */
    function openfga_resolve_user_id($user): string
    {
        // String identifier
        if (is_string($user)) {
            return $user;
        }

        // Numeric identifier
        if (is_numeric($user)) {
            return 'user:' . $user;
        }

        // User object with custom method
        if (is_object($user) && method_exists($user, 'authorizationUser')) {
            return $user->authorizationUser();
        }

        // User object with alternative method
        if (is_object($user) && method_exists($user, 'getAuthorizationUserId')) {
            return $user->getAuthorizationUserId();
        }

        // Authenticatable user
        if (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
            return 'user:' . $user->getAuthIdentifier();
        }

        throw new \InvalidArgumentException('Cannot resolve user identifier for: ' . gettype($user));
    }
}

if (!function_exists('openfga_resolve_object')) {
    /**
     * Resolve an object identifier for OpenFGA.
     *
     * @param mixed $object
     *
     * @return string
     */
    function openfga_resolve_object($object): string
    {
        // String in object:id format
        if (is_string($object)) {
            return $object;
        }

        // Model with authorization support
        if (is_object($object) && method_exists($object, 'authorizationObject')) {
            return $object->authorizationObject();
        }

        // Model with authorization type method
        if (is_object($object) && method_exists($object, 'authorizationType') && method_exists($object, 'getKey')) {
            return $object->authorizationType() . ':' . $object->getKey();
        }

        // Eloquent model fallback
        if (is_object($object) && method_exists($object, 'getTable') && method_exists($object, 'getKey')) {
            return $object->getTable() . ':' . $object->getKey();
        }

        throw new \InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($object));
    }
}

if (!function_exists('openfga_manager')) {
    /**
     * Get the OpenFGA manager instance.
     *
     * @param string|null $connection
     *
     * @return \OpenFGA\Laravel\OpenFgaManager
     */
    function openfga_manager(?string $connection = null): OpenFgaManager
    {
        $manager = app(OpenFgaManager::class);
        
        return $connection ? $manager->connection($connection) : $manager;
    }
}

if (!function_exists('openfga_query')) {
    /**
     * Create a new OpenFGA query builder.
     *
     * @param string|null $connection
     *
     * @return \OpenFGA\Laravel\Query\AuthorizationQuery
     */
    function openfga_query(?string $connection = null): \OpenFGA\Laravel\Query\AuthorizationQuery
    {
        return openfga_manager($connection)->query();
    }
}