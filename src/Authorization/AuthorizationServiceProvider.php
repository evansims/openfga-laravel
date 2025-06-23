<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Authorization;

use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Service provider for OpenFGA authorization integration.
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the OpenFGA Gate implementation
        $this->app->singleton(GateContract::class, function ($app) {
            return new OpenFgaGate(
                $app[OpenFgaManager::class],
                $app,
                function () {
                    return Auth::user();
                }
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerOpenFgaGates();
        $this->registerGlobalHelpers();
    }

    /**
     * Register OpenFGA-specific gates.
     */
    protected function registerOpenFgaGates(): void
    {
        $gate = $this->app[GateContract::class];

        // Register a generic OpenFGA gate
        $gate->define('openfga', function ($user, string $relation, $resource, ?string $connection = null) {
            $manager = $this->app[OpenFgaManager::class];
            
            $userId = $this->resolveUserId($user);
            $object = $this->resolveObject($resource);

            return $manager->connection($connection)->check($userId, $relation, $object);
        });

        // Register wildcard gate for OpenFGA permissions
        $gate->before(function ($user, $ability, $arguments) {
            // Check if this looks like an OpenFGA permission check
            if (str_starts_with($ability, 'openfga:')) {
                $relation = str_replace('openfga:', '', $ability);
                $resource = $arguments[0] ?? null;
                $connection = $arguments[1] ?? null;

                if ($resource) {
                    $manager = $this->app[OpenFgaManager::class];
                    
                    $userId = $this->resolveUserId($user);
                    $object = $this->resolveObject($resource);

                    return $manager->connection($connection)->check($userId, $relation, $object);
                }
            }

            return null; // Let other gates handle this
        });
    }

    /**
     * Register global authorization helpers.
     */
    protected function registerGlobalHelpers(): void
    {
        if (!function_exists('openfga_can')) {
            /**
             * Check if the current user has the given OpenFGA permission.
             *
             * @param string      $relation
             * @param mixed       $resource
             * @param string|null $connection
             *
             * @return bool
             */
            function openfga_can(string $relation, $resource, ?string $connection = null): bool
            {
                if (!Auth::check()) {
                    return false;
                }

                $manager = app(OpenFgaManager::class);
                $provider = app(AuthorizationServiceProvider::class);
                
                $userId = $provider->resolveUserId(Auth::user());
                $object = $provider->resolveObject($resource);

                return $manager->connection($connection)->check($userId, $relation, $object);
            }
        }

        if (!function_exists('openfga_cannot')) {
            /**
             * Check if the current user does NOT have the given OpenFGA permission.
             *
             * @param string      $relation
             * @param mixed       $resource
             * @param string|null $connection
             *
             * @return bool
             */
            function openfga_cannot(string $relation, $resource, ?string $connection = null): bool
            {
                return !openfga_can($relation, $resource, $connection);
            }
        }

        if (!function_exists('openfga_can_any')) {
            /**
             * Check if the current user has any of the given OpenFGA permissions.
             *
             * @param array<string> $relations
             * @param mixed         $resource
             * @param string|null   $connection
             *
             * @return bool
             */
            function openfga_can_any(array $relations, $resource, ?string $connection = null): bool
            {
                foreach ($relations as $relation) {
                    if (openfga_can($relation, $resource, $connection)) {
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
             * @param mixed         $resource
             * @param string|null   $connection
             *
             * @return bool
             */
            function openfga_can_all(array $relations, $resource, ?string $connection = null): bool
            {
                foreach ($relations as $relation) {
                    if (!openfga_can($relation, $resource, $connection)) {
                        return false;
                    }
                }

                return true;
            }
        }
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     *
     * @return string
     */
    public function resolveUserId($user): string
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
    public function resolveObject($resource): string
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

        throw new \InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($resource));
    }
}