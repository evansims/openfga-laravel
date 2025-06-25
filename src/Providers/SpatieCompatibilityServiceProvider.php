<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use OpenFGA\Laravel\Http\Middleware\SpatiePermissionMiddleware;
use OpenFGA\Laravel\Http\Middleware\SpatieRoleMiddleware;

/**
 * Service provider for Spatie Laravel Permission compatibility
 */
class SpatieCompatibilityServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/spatie-compatibility.php',
            'spatie-compatibility'
        );

        $this->app->singleton(SpatieCompatibility::class, function ($app) {
            $compatibility = new SpatieCompatibility();
            
            // Load custom mappings from config
            $permissionMappings = config('spatie-compatibility.permission_mappings', []);
            foreach ($permissionMappings as $permission => $relation) {
                $compatibility->addPermissionMapping($permission, $relation);
            }

            $roleMappings = config('spatie-compatibility.role_mappings', []);
            foreach ($roleMappings as $role => $relation) {
                $compatibility->addRoleMapping($role, $relation);
            }

            return $compatibility;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (!config('spatie-compatibility.enabled', false)) {
            return;
        }

        $this->bootPublishing();
        $this->bootMiddleware();
        $this->bootBladeDirectives();
    }

    /**
     * Boot publishing configuration
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

    /**
     * Boot middleware registration
     */
    private function bootMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        
        if (config('spatie-compatibility.middleware_aliases.role', true)) {
            $router->aliasMiddleware('role', SpatieRoleMiddleware::class);
        }

        if (config('spatie-compatibility.middleware_aliases.permission', true)) {
            $router->aliasMiddleware('permission', SpatiePermissionMiddleware::class);
        }
    }

    /**
     * Boot Blade directives registration
     */
    private function bootBladeDirectives(): void
    {
        // Register the SpatieBladeServiceProvider if Blade directives are enabled
        $enabledDirectives = array_filter(config('spatie-compatibility.blade_directives', []));
        
        if (!empty($enabledDirectives)) {
            $this->app->register(SpatieBladeServiceProvider::class);
        }
    }
}