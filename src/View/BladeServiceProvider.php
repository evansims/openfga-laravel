<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Service provider for OpenFGA Blade directives and view helpers.
 */
class BladeServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->registerBladeDirectives();
        $this->registerViewComposer();
    }

    /**
     * Register OpenFGA Blade directives.
     */
    protected function registerBladeDirectives(): void
    {
        // @openfgacan directive
        Blade::if('openfgacan', function (string $relation, $object, ?string $connection = null) {
            if (!Auth::check()) {
                return false;
            }

            $manager = app(OpenFgaManager::class);
            $user = Auth::user();
            
            $userId = $this->resolveUserId($user);
            $objectId = $this->resolveObject($object);

            return $manager->connection($connection)->check($userId, $relation, $objectId);
        });

        // @openfgacannot directive (opposite of @openfgacan)
        Blade::if('openfgacannot', function (string $relation, $object, ?string $connection = null) {
            return !$this->app[static::class]->checkBladePermission($relation, $object, $connection);
        });

        // @openfgacanany directive - check if user has any of the given permissions
        Blade::if('openfgacanany', function (array $relations, $object, ?string $connection = null) {
            if (!Auth::check()) {
                return false;
            }

            foreach ($relations as $relation) {
                if ($this->app[static::class]->checkBladePermission($relation, $object, $connection)) {
                    return true;
                }
            }

            return false;
        });

        // @openfgacanall directive - check if user has all of the given permissions
        Blade::if('openfgacanall', function (array $relations, $object, ?string $connection = null) {
            if (!Auth::check()) {
                return false;
            }

            foreach ($relations as $relation) {
                if (!$this->app[static::class]->checkBladePermission($relation, $object, $connection)) {
                    return false;
                }
            }

            return true;
        });

        // @openfgauser directive - check if current user matches the given user identifier
        Blade::if('openfgauser', function (string $userId) {
            if (!Auth::check()) {
                return false;
            }

            $user = Auth::user();
            $currentUserId = $this->app[static::class]->resolveUserId($user);

            return $currentUserId === $userId;
        });

        // @openfgaguest directive - check if user is not authenticated
        Blade::if('openfgaguest', function () {
            return !Auth::check();
        });

        // @openfgajs directive - generate JavaScript helpers
        Blade::directive('openfgajs', function ($expression) {
            return "<?php echo app(\\OpenFGA\\Laravel\\View\\JavaScriptHelper::class)->bladeDirective({$expression}); ?>";
        });

        // For backwards compatibility with Laravel's built-in @can directive
        // Override Laravel's @can to support OpenFGA when object contains ':'
        $originalCanDirective = Blade::getCustomDirectives()['can'] ?? null;
        
        Blade::if('can', function ($ability, $arguments = null) use ($originalCanDirective) {
            // If it looks like an OpenFGA permission check (object contains ':')
            if (is_string($arguments) && str_contains($arguments, ':')) {
                return $this->app[static::class]->checkBladePermission($ability, $arguments);
            }

            // Fall back to Laravel's original @can behavior
            if ($originalCanDirective) {
                return $originalCanDirective($ability, $arguments);
            }

            // Default Laravel @can behavior
            return Auth::check() && Auth::user()->can($ability, $arguments);
        });
    }

    /**
     * Register view composer for permission data.
     */
    protected function registerViewComposer(): void
    {
        // Register a view composer that makes OpenFGA helpers available in all views
        view()->composer('*', function ($view) {
            $view->with('openfga', new class {
                public function can(string $relation, $object, ?string $connection = null): bool
                {
                    if (!Auth::check()) {
                        return false;
                    }

                    $provider = app(BladeServiceProvider::class);
                    return $provider->checkBladePermission($relation, $object, $connection);
                }

                public function cannot(string $relation, $object, ?string $connection = null): bool
                {
                    return !$this->can($relation, $object, $connection);
                }

                public function canAny(array $relations, $object, ?string $connection = null): bool
                {
                    foreach ($relations as $relation) {
                        if ($this->can($relation, $object, $connection)) {
                            return true;
                        }
                    }
                    return false;
                }

                public function canAll(array $relations, $object, ?string $connection = null): bool
                {
                    foreach ($relations as $relation) {
                        if (!$this->can($relation, $object, $connection)) {
                            return false;
                        }
                    }
                    return true;
                }

                public function user(): ?string
                {
                    if (!Auth::check()) {
                        return null;
                    }

                    $provider = app(BladeServiceProvider::class);
                    return $provider->resolveUserId(Auth::user());
                }
            });
        });
    }

    /**
     * Check blade permission (public method for use by directives).
     *
     * @param string      $relation
     * @param mixed       $object
     * @param string|null $connection
     *
     * @return bool
     */
    public function checkBladePermission(string $relation, $object, ?string $connection = null): bool
    {
        if (!Auth::check()) {
            return false;
        }

        $manager = app(OpenFgaManager::class);
        $user = Auth::user();
        
        $userId = $this->resolveUserId($user);
        $objectId = $this->resolveObject($object);

        return $manager->connection($connection)->check($userId, $relation, $objectId);
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
     * @param mixed $object
     *
     * @return string
     */
    public function resolveObject($object): string
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