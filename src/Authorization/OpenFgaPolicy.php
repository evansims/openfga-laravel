<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Base policy class that provides OpenFGA integration.
 */
abstract class OpenFgaPolicy
{
    /**
     * Create a new policy instance.
     */
    public function __construct(
        protected OpenFgaManager $manager
    ) {}

    /**
     * Check if the user has the given permission on the resource.
     *
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     * @param string                                      $relation
     * @param mixed                                       $resource
     * @param string|null                                 $connection
     *
     * @return bool
     */
    protected function can(Authenticatable $user, string $relation, $resource, ?string $connection = null): bool
    {
        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($resource);

        return $this->manager
            ->connection($connection)
            ->check($userId, $relation, $object);
    }

    /**
     * Check if the user has any of the given permissions on the resource.
     *
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     * @param array<string>                               $relations
     * @param mixed                                       $resource
     * @param string|null                                 $connection
     *
     * @return bool
     */
    protected function canAny(Authenticatable $user, array $relations, $resource, ?string $connection = null): bool
    {
        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($resource);

        foreach ($relations as $relation) {
            if ($this->manager->connection($connection)->check($userId, $relation, $object)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user has all of the given permissions on the resource.
     *
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     * @param array<string>                               $relations
     * @param mixed                                       $resource
     * @param string|null                                 $connection
     *
     * @return bool
     */
    protected function canAll(Authenticatable $user, array $relations, $resource, ?string $connection = null): bool
    {
        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($resource);

        foreach ($relations as $relation) {
            if (!$this->manager->connection($connection)->check($userId, $relation, $object)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     *
     * @return string
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

        // Numeric ID - try to infer type from policy class name
        if (is_numeric($resource)) {
            $type = $this->inferResourceType();
            return $type . ':' . $resource;
        }

        throw new \InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($resource));
    }

    /**
     * Infer the resource type from the policy class name.
     *
     * @return string
     */
    protected function inferResourceType(): string
    {
        $className = class_basename(static::class);
        
        // Remove 'Policy' suffix and convert to snake_case
        $type = str_replace('Policy', '', $className);
        
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $type));
    }

    /**
     * Get the default resource type for this policy.
     * Override this method to customize the resource type.
     *
     * @return string
     */
    protected function getResourceType(): string
    {
        return $this->inferResourceType();
    }

    /**
     * Create an object identifier for the given ID.
     *
     * @param mixed $id
     *
     * @return string
     */
    protected function objectId($id): string
    {
        return $this->getResourceType() . ':' . $id;
    }
}