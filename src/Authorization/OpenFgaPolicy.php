<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Authorization;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Contracts\{AuthorizationObject, AuthorizationType};
use OpenFGA\Laravel\Helpers\ModelKeyHelper;
use OpenFGA\Laravel\OpenFgaManager;

use function gettype;
use function is_object;
use function is_scalar;
use function is_string;

/**
 * Base policy class that provides OpenFGA integration.
 */
abstract class OpenFgaPolicy
{
    /**
     * Create a new policy instance.
     *
     * @param OpenFgaManager $manager
     */
    public function __construct(
        protected OpenFgaManager $manager,
    ) {
    }

    /**
     * Check if the user has the given permission on the resource.
     *
     * @param Authenticatable $user
     * @param string          $relation
     * @param mixed           $resource
     * @param string|null     $connection
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    protected function can(Authenticatable $user, string $relation, $resource, ?string $connection = null): bool
    {
        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($resource);

        return $this->manager->check($userId, $relation, $object, [], [], $connection);
    }

    /**
     * Check if the user has all of the given permissions on the resource.
     *
     * @param Authenticatable $user
     * @param array<string>   $relations
     * @param mixed           $resource
     * @param string|null     $connection
     *
     * @throws InvalidArgumentException
     */
    protected function canAll(Authenticatable $user, array $relations, $resource, ?string $connection = null): bool
    {
        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($resource);

        foreach ($relations as $relation) {
            if (! $this->manager->check($userId, $relation, $object, [], [], $connection)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the user has any of the given permissions on the resource.
     *
     * @param Authenticatable $user
     * @param array<string>   $relations
     * @param mixed           $resource
     * @param string|null     $connection
     *
     * @throws InvalidArgumentException
     */
    protected function canAny(Authenticatable $user, array $relations, $resource, ?string $connection = null): bool
    {
        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($resource);

        foreach ($relations as $relation) {
            if ($this->manager->check($userId, $relation, $object, [], [], $connection)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the default resource type for this policy.
     * Override this method to customize the resource type.
     */
    protected function getResourceType(): string
    {
        return $this->inferResourceType();
    }

    /**
     * Infer the resource type from the policy class name.
     */
    protected function inferResourceType(): string
    {
        $className = class_basename(static::class);

        // Remove 'Policy' suffix and convert to snake_case
        $type = str_replace('Policy', '', $className);

        $result = preg_replace('/([a-z])([A-Z])/', '$1_$2', $type);

        return strtolower($result ?? $type);
    }

    /**
     * Create an object identifier for the given ID.
     *
     * @param mixed $id
     *
     * @throws InvalidArgumentException
     */
    protected function objectId($id): string
    {
        if (is_scalar($id) || (is_object($id) && method_exists($id, '__toString'))) {
            return $this->getResourceType() . ':' . (string) $id;
        }

        throw new InvalidArgumentException('ID must be a scalar value or implement __toString()');
    }

    /**
     * Resolve the object identifier for OpenFGA.
     *
     * @param mixed $resource
     *
     * @throws InvalidArgumentException
     */
    protected function resolveObject($resource): string
    {
        // String in object:id format
        if (is_string($resource)) {
            return $resource;
        }

        // Model with authorization support
        if (is_object($resource) && method_exists($resource, 'authorizationObject')) {
            /** @var AuthorizationObject&object $resource */
            return $resource->authorizationObject();
        }

        // Model with authorization type method
        if (is_object($resource) && method_exists($resource, 'authorizationType') && method_exists($resource, 'getKey')) {
            /** @var AuthorizationType&Model&object $resource */
            $type = $resource->authorizationType();
            $key = ModelKeyHelper::stringId($resource);

            return $type . ':' . $key;
        }

        // Eloquent model fallback
        if (is_object($resource)) {
            /** @var object $resource */
            if (method_exists($resource, 'getTable') && method_exists($resource, 'getKey')) {
                /** @var Model $resource */
                $table = $resource->getTable();
                $key = ModelKeyHelper::stringId($resource);

                return $table . ':' . $key;
            }
        }

        // Numeric ID - try to infer type from policy class name
        if (is_numeric($resource)) {
            $type = $this->inferResourceType();

            return $type . ':' . (string) $resource;
        }

        throw new InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($resource));
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param Authenticatable $user The authenticated user
     *
     * @throws InvalidArgumentException
     *
     * @return string The user identifier for OpenFGA
     */
    protected function resolveUserId(Authenticatable $user): string
    {
        if (method_exists($user, 'authorizationUser')) {
            /** @var mixed $result */
            $result = $user->authorizationUser();

            if (is_string($result)) {
                return $result;
            }

            if (is_scalar($result) || (is_object($result) && method_exists($result, '__toString'))) {
                return (string) $result;
            }

            throw new InvalidArgumentException('authorizationUser() must return a string or stringable value');
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            /** @var mixed $result */
            $result = $user->getAuthorizationUserId();

            if (is_string($result)) {
                return $result;
            }

            if (is_scalar($result) || (is_object($result) && method_exists($result, '__toString'))) {
                return (string) $result;
            }

            throw new InvalidArgumentException('getAuthorizationUserId() must return a string or stringable value');
        }

        $identifier = $user->getAuthIdentifier();

        if (null === $identifier || (! is_string($identifier) && ! is_numeric($identifier))) {
            throw new InvalidArgumentException('User identifier must be string or numeric');
        }

        return 'user:' . (string) $identifier;
    }
}
