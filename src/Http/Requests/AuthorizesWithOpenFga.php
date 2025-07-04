<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use OpenFGA\Laravel\Helpers\ModelKeyHelper;
use OpenFGA\Laravel\OpenFgaManager;

use function gettype;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Trait for adding OpenFGA authorization to Form Requests.
 */
trait AuthorizesWithOpenFga // @phpstan-ignore trait.unused
{
    /**
     * Authorize the current user for all of the given permissions, throwing an exception if unauthorized.
     *
     * @param array<string> $relations  The permissions/relations to check
     * @param mixed         $resource   The resource to check against
     * @param string|null   $message    Custom authorization failure message
     * @param string|null   $connection Optional OpenFGA connection name
     *
     * @throws AuthorizationException
     */
    protected function authorizeAllOpenFga(array $relations, $resource, ?string $message = null, ?string $connection = null): void
    {
        if (! $this->canAllOpenFga($relations, $resource, $connection)) {
            $object = $this->resolveObject($resource);
            $relationsList = implode(', ', $relations);
            $defaultMessage = sprintf("You do not have all required permissions (%s) on '%s'.", $relationsList, $object);

            throw new AuthorizationException($message ?? $defaultMessage);
        }
    }

    /**
     * Authorize the current user for any of the given permissions, throwing an exception if unauthorized.
     *
     * @param array<string> $relations  The permissions/relations to check
     * @param mixed         $resource   The resource to check against
     * @param string|null   $message    Custom authorization failure message
     * @param string|null   $connection Optional OpenFGA connection name
     *
     * @throws AuthorizationException
     */
    protected function authorizeAnyOpenFga(array $relations, $resource, ?string $message = null, ?string $connection = null): void
    {
        if (! $this->canAnyOpenFga($relations, $resource, $connection)) {
            $object = $this->resolveObject($resource);
            $relationsList = implode(', ', $relations);
            $defaultMessage = sprintf("You do not have any of the required permissions (%s) on '%s'.", $relationsList, $object);

            throw new AuthorizationException($message ?? $defaultMessage);
        }
    }

    /**
     * Authorize the current user for the given permission, throwing an exception if unauthorized.
     *
     * @param string      $relation   The permission/relation to check
     * @param mixed       $resource   The resource to check against
     * @param string|null $message    Custom authorization failure message
     * @param string|null $connection Optional OpenFGA connection name
     *
     * @throws AuthorizationException
     */
    protected function authorizeOpenFga(string $relation, $resource, ?string $message = null, ?string $connection = null): void
    {
        if (! $this->canOpenFga($relation, $resource, $connection)) {
            $object = $this->resolveObject($resource);
            $defaultMessage = sprintf("You do not have permission '%s' on '%s'.", $relation, $object);

            throw new AuthorizationException($message ?? $defaultMessage);
        }
    }

    /**
     * Helper method to authorize against a route parameter, throwing an exception if unauthorized.
     *
     * @param string      $parameterName The route parameter name
     * @param string      $relation      The permission/relation to check
     * @param string|null $message       Custom authorization failure message
     * @param string|null $connection    Optional OpenFGA connection name
     *
     * @throws AuthorizationException
     */
    protected function authorizeRouteParameter(string $parameterName, string $relation, ?string $message = null, ?string $connection = null): void
    {
        if (! method_exists($this, 'route')) {
            throw new AuthorizationException('Route method not available');
        }

        $resource = $this->route($parameterName);

        if (! $resource) {
            throw new AuthorizationException(sprintf("Route parameter '%s' not found", $parameterName));
        }

        $this->authorizeOpenFga($relation, $resource, $message, $connection);
    }

    /**
     * Check if the current user has all of the given permissions on a resource.
     *
     * @param array<string> $relations  The permissions/relations to check
     * @param mixed         $resource   The resource to check against
     * @param string|null   $connection Optional OpenFGA connection name
     */
    protected function canAllOpenFga(array $relations, $resource, ?string $connection = null): bool
    {
        foreach ($relations as $relation) {
            if (! $this->canOpenFga($relation, $resource, $connection)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the current user has any of the given permissions on a resource.
     *
     * @param array<string> $relations  The permissions/relations to check
     * @param mixed         $resource   The resource to check against
     * @param string|null   $connection Optional OpenFGA connection name
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
     * Check if the current user has the given permission on a resource.
     *
     * @param string      $relation   The permission/relation to check
     * @param mixed       $resource   The resource to check against
     * @param string|null $connection Optional OpenFGA connection name
     */
    protected function canOpenFga(string $relation, $resource, ?string $connection = null): bool
    {
        if (! Auth::check()) {
            return false;
        }

        $manager = app(OpenFgaManager::class);

        /** @var Authenticatable|null $user */
        $user = Auth::user();

        if (null === $user) {
            return false;
        }

        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($resource);

        return $manager->connection($connection)->check($userId, $relation, $object);
    }

    /**
     * Helper method to check authorization against a route parameter.
     *
     * @param string      $parameterName The route parameter name
     * @param string      $relation      The permission/relation to check
     * @param string|null $connection    Optional OpenFGA connection name
     */
    protected function canRouteParameter(string $parameterName, string $relation, ?string $connection = null): bool
    {
        if (! method_exists($this, 'route')) {
            return false;
        }

        $resource = $this->route($parameterName);

        if (! $resource) {
            return false;
        }

        return $this->canOpenFga($relation, $resource, $connection);
    }

    /**
     * Resolve the object identifier for OpenFGA.
     *
     * @param mixed $resource
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
            return $resource->authorizationType() . ':' . ModelKeyHelper::stringId($resource);
        }

        // Eloquent model fallback
        if (is_object($resource) && method_exists($resource, 'getTable') && method_exists($resource, 'getKey')) {
            return $resource->getTable() . ':' . ModelKeyHelper::stringId($resource);
        }

        // Route parameter resolution
        if (is_string($resource) && method_exists($this, 'route')) {
            $routeParam = $this->route($resource);

            if ($routeParam) {
                return $this->resolveObject($routeParam);
            }
        }

        throw new InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($resource));
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param  Authenticatable $user The authenticated user
     * @return string          The user identifier for OpenFGA
     */
    protected function resolveUserId(Authenticatable $user): string
    {
        if (method_exists($user, 'authorizationUser')) {
            return $user->authorizationUser();
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            return $user->getAuthorizationUserId();
        }

        return 'user:' . $user->getAuthIdentifier();
    }
}
