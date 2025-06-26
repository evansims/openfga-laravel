<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Providers;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use OpenFGA\Laravel\Http\Middleware\{SpatiePermissionMiddleware, SpatieRoleMiddleware};
use Override;

use function is_array;
use function is_string;

/**
 * Service provider for Spatie Laravel Permission compatibility.
 *
 * @internal
 */
final class SpatieCompatibilityServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     */
    public function boot(): void
    {
        if (true !== config('spatie-compatibility.enabled', false)) {
            return;
        }

        $this->bootPublishing();
        $this->bootMiddleware();
        $this->bootBladeDirectives();
    }

    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/spatie-compatibility.php',
            'spatie-compatibility',
        );

        $this->app->singleton(SpatieCompatibility::class, static function (): SpatieCompatibility {
            $compatibility = new SpatieCompatibility;

            // Load custom mappings from config
            /** @var mixed $permissionMappings */
            $permissionMappings = config('spatie-compatibility.permission_mappings', []);

            if (is_array($permissionMappings)) {
                /**
                 * @var int|string $permission
                 * @var mixed      $relation
                 */
                foreach ($permissionMappings as $permission => $relation) {
                    if (is_string($permission) && is_string($relation)) {
                        $compatibility->addPermissionMapping($permission, $relation);
                    }
                }
            }

            /** @var mixed $roleMappings */
            $roleMappings = config('spatie-compatibility.role_mappings', []);

            if (is_array($roleMappings)) {
                /**
                 * @var int|string $role
                 * @var mixed      $relation
                 */
                foreach ($roleMappings as $role => $relation) {
                    if (is_string($role) && is_string($relation)) {
                        $compatibility->addRoleMapping($role, $relation);
                    }
                }
            }

            return $compatibility;
        });
    }

    /**
     * Boot Blade directives registration.
     */
    private function bootBladeDirectives(): void
    {
        // Register the SpatieBladeServiceProvider if Blade directives are enabled
        /** @var mixed $directives */
        $directives = config('spatie-compatibility.blade_directives', []);
        $enabledDirectives = is_array($directives) ? array_filter($directives, static fn ($value): bool => (bool) $value) : [];

        if ([] !== $enabledDirectives) {
            $this->app->register(SpatieBladeServiceProvider::class);
        }
    }

    /**
     * Boot middleware registration.
     *
     * @throws BindingResolutionException
     */
    private function bootMiddleware(): void
    {
        $router = $this->app->make(Router::class);

        /** @var mixed $roleEnabled */
        $roleEnabled = config('spatie-compatibility.middleware_aliases.role', true);

        if (true === $roleEnabled) {
            $router->aliasMiddleware('role', SpatieRoleMiddleware::class);
        }

        /** @var mixed $permissionEnabled */
        $permissionEnabled = config('spatie-compatibility.middleware_aliases.permission', true);

        if (true === $permissionEnabled) {
            $router->aliasMiddleware('permission', SpatiePermissionMiddleware::class);
        }
    }

    /**
     * Boot publishing configuration.
     */
    private function bootPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/spatie-compatibility.php' => config_path('spatie-compatibility.php'),
            ], 'config');

            $this->publishes([
                __DIR__ . '/../../config/spatie-compatibility.php' => config_path('spatie-compatibility.php'),
            ], 'openfga-spatie-config');
        }
    }
}
