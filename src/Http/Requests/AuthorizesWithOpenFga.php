<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Trait for adding OpenFGA authorization to Form Requests.
 */
trait AuthorizesWithOpenFga
{
    /**
     * Check if the current user has the given permission on a resource.
     *
     * @param string      $relation The permission/relation to check
     * @param mixed       $resource The resource to check against
     * @param string|null $connection Optional OpenFGA connection name
     *
     * @return bool
     */
    protected function canOpenFga(string $relation, $resource, ?string $connection = null): bool
    {
        if (!Auth::check()) {
            return false;
        }

        $manager = app(OpenFgaManager::class);
        $user = Auth::user();
        
        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($resource);

        return $manager->connection($connection)->check($userId, $relation, $object);
    }

    /**
     * Check if the current user has any of the given permissions on a resource.
     *
     * @param array<string> $relations The permissions/relations to check
     * @param mixed         $resource The resource to check against
     * @param string|null   $connection Optional OpenFGA connection name
     *
     * @return bool
     */
    protected function canAnyOpenFga(array $relations, $resource, ?string $connection = null): bool
    {
        foreach ($relations as $relation) {
            if ($this->canOpenFga($relation, $resource, $connection)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the current user has all of the given permissions on a resource.
     *
     * @param array<string> $relations The permissions/relations to check
     * @param mixed         $resource The resource to check against
     * @param string|null   $connection Optional OpenFGA connection name
     *
     * @return bool
     */
    protected function canAllOpenFga(array $relations, $resource, ?string $connection = null): bool
    {
        foreach ($relations as $relation) {
            if (!$this->canOpenFga($relation, $resource, $connection)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Authorize the current user for the given permission, throwing an exception if unauthorized.
     *
     * @param string      $relation The permission/relation to check
     * @param mixed       $resource The resource to check against
     * @param string|null $message Custom authorization failure message
     * @param string|null $connection Optional OpenFGA connection name
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function authorizeOpenFga(string $relation, $resource, ?string $message = null, ?string $connection = null): void
    {
        if (!$this->canOpenFga($relation, $resource, $connection)) {
            $object = $this->resolveObject($resource);
            $defaultMessage = "You do not have permission '{$relation}' on '{$object}'.";
            
            throw new AuthorizationException($message ?? $defaultMessage);
        }
    }

    /**
     * Authorize the current user for any of the given permissions, throwing an exception if unauthorized.
     *
     * @param array<string> $relations The permissions/relations to check
     * @param mixed         $resource The resource to check against
     * @param string|null   $message Custom authorization failure message
     * @param string|null   $connection Optional OpenFGA connection name
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function authorizeAnyOpenFga(array $relations, $resource, ?string $message = null, ?string $connection = null): void
    {
        if (!$this->canAnyOpenFga($relations, $resource, $connection)) {
            $object = $this->resolveObject($resource);
            $relationsList = implode(', ', $relations);
            $defaultMessage = "You do not have any of the required permissions ({$relationsList}) on '{$object}'.";
            
            throw new AuthorizationException($message ?? $defaultMessage);
        }
    }

    /**
     * Authorize the current user for all of the given permissions, throwing an exception if unauthorized.
     *
     * @param array<string> $relations The permissions/relations to check
     * @param mixed         $resource The resource to check against
     * @param string|null   $message Custom authorization failure message
     * @param string|null   $connection Optional OpenFGA connection name
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function authorizeAllOpenFga(array $relations, $resource, ?string $message = null, ?string $connection = null): void
    {
        if (!$this->canAllOpenFga($relations, $resource, $connection)) {
            $object = $this->resolveObject($resource);
            $relationsList = implode(', ', $relations);
            $defaultMessage = "You do not have all required permissions ({$relationsList}) on '{$object}'.";
            
            throw new AuthorizationException($message ?? $defaultMessage);
        }
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     *
     * @return string
     */
    protected function resolveUserId($user): string
    {
        if (method_exists($user, 'authorizationUser')) {
            return $user->authorizationUser();
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            return $user->getAuthorizationUserId();
        }

        return 'user:' . $user->getAuthIdentifier();
    }

    /**
     * Resolve the object identifier for OpenFGA.
     *
     * @param mixed $resource
     *
     * @return string
     */
    protected function resolveObject($resource): string
    {
        // String in object:id format
        if (is_string($resource)) {
            return $resource;
        }

        // Model with authorization support
        if (is_object($resource) && method_exists($resource, 'authorizationObject')) {
            return $resource->authorizationObject();
        }

        // Model with authorization type method
        if (is_object($resource) && method_exists($resource, 'authorizationType') && method_exists($resource, 'getKey')) {
            return $resource->authorizationType() . ':' . $resource->getKey();
        }

        // Eloquent model fallback
        if (is_object($resource) && method_exists($resource, 'getTable') && method_exists($resource, 'getKey')) {
            return $resource->getTable() . ':' . $resource->getKey();
        }

        // Route parameter resolution
        if (is_string($resource) && method_exists($this, 'route')) {
            $routeParam = $this->route($resource);
            if ($routeParam) {
                return $this->resolveObject($routeParam);
            }
        }

        throw new \InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($resource));
    }

    /**
     * Helper method to check authorization against a route parameter.
     *
     * @param string      $parameterName The route parameter name
     * @param string      $relation The permission/relation to check
     * @param string|null $connection Optional OpenFGA connection name
     *
     * @return bool
     */
    protected function canRouteParameter(string $parameterName, string $relation, ?string $connection = null): bool
    {
        if (!method_exists($this, 'route')) {
            return false;
        }

        $resource = $this->route($parameterName);
        
        if (!$resource) {
            return false;
        }

        return $this->canOpenFga($relation, $resource, $connection);
    }

    /**
     * Helper method to authorize against a route parameter, throwing an exception if unauthorized.
     *
     * @param string      $parameterName The route parameter name
     * @param string      $relation The permission/relation to check
     * @param string|null $message Custom authorization failure message
     * @param string|null $connection Optional OpenFGA connection name
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function authorizeRouteParameter(string $parameterName, string $relation, ?string $message = null, ?string $connection = null): void
    {
        if (!method_exists($this, 'route')) {
            throw new AuthorizationException('Route method not available');
        }

        $resource = $this->route($parameterName);
        
        if (!$resource) {
            throw new AuthorizationException("Route parameter '{$parameterName}' not found");
        }

        $this->authorizeOpenFga($relation, $resource, $message, $connection);
    }
}