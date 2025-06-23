<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Authorization;

use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * OpenFGA-powered Gate implementation that integrates with Laravel's authorization system.
 */
class OpenFgaGate extends Gate
{
    /**
     * Create a new OpenFGA Gate instance.
     */
    public function __construct(
        protected OpenFgaManager $manager,
        \Illuminate\Contracts\Container\Container $container,
        callable $userResolver
    ) {
        parent::__construct($container, $userResolver);
    }

    /**
     * Determine if the given ability should be granted for the current user.
     *
     * @param string                              $ability
     * @param array|mixed                         $arguments
     * @param \Illuminate\Contracts\Auth\Authenticatable|null $user
     *
     * @return bool
     */
    public function check($ability, $arguments = [], $user = null): bool
    {
        // First check if this is an OpenFGA permission check
        if ($this->isOpenFgaPermission($ability, $arguments)) {
            return $this->checkOpenFgaPermission($ability, $arguments, $user);
        }

        // Fall back to Laravel's default behavior for non-OpenFGA checks
        return parent::check($ability, $arguments, $user);
    }

    /**
     * Determine if this is an OpenFGA permission check.
     *
     * @param string      $ability
     * @param array|mixed $arguments
     *
     * @return bool
     */
    protected function isOpenFgaPermission(string $ability, $arguments): bool
    {
        // Check if we have a clear object identifier in arguments
        $arguments = is_array($arguments) ? $arguments : [$arguments];
        
        foreach ($arguments as $argument) {
            if (is_string($argument) && str_contains($argument, ':')) {
                return true; // Looks like object:id format
            }
            
            if (is_object($argument) && method_exists($argument, 'authorizationObject')) {
                return true; // Model with authorization support
            }
        }

        return false;
    }

    /**
     * Check OpenFGA permission.
     *
     * @param string                              $ability
     * @param array|mixed                         $arguments
     * @param \Illuminate\Contracts\Auth\Authenticatable|null $user
     *
     * @return bool
     */
    protected function checkOpenFgaPermission(string $ability, $arguments, ?Authenticatable $user = null): bool
    {
        $user = $user ?: $this->resolveUser();
        
        if (!$user) {
            return false;
        }

        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($arguments);

        if (!$object) {
            return false;
        }

        return $this->manager->check($userId, $ability, $object);
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
     * Resolve the object from arguments.
     *
     * @param array|mixed $arguments
     *
     * @return string|null
     */
    protected function resolveObject($arguments): ?string
    {
        $arguments = is_array($arguments) ? $arguments : [$arguments];

        foreach ($arguments as $argument) {
            // String in object:id format
            if (is_string($argument) && str_contains($argument, ':')) {
                return $argument;
            }

            // Model with authorization support
            if (is_object($argument) && method_exists($argument, 'authorizationObject')) {
                return $argument->authorizationObject();
            }

            // Model with authorization type method
            if (is_object($argument) && method_exists($argument, 'authorizationType') && method_exists($argument, 'getKey')) {
                return $argument->authorizationType() . ':' . $argument->getKey();
            }

            // Eloquent model fallback
            if (is_object($argument) && method_exists($argument, 'getTable') && method_exists($argument, 'getKey')) {
                return $argument->getTable() . ':' . $argument->getKey();
            }
        }

        return null;
    }
}