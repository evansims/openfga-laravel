<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use Override;

use function is_array;

/**
 * Service provider for Spatie-compatible Blade directives.
 */
final class SpatieBladeServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerSpatieBladeDirectives();
    }

    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(SpatieCompatibility::class);
    }

    /**
     * Register Spatie-compatible Blade directives.
     */
    private function registerSpatieBladeDirectives(): void
    {
        // @hasrole directive
        Blade::if('hasrole', static function ($role, $context = null) {
            $user = auth()->user();

            if (! $user) {
                return false;
            }

            $compatibility = app(SpatieCompatibility::class);

            return $compatibility->hasRole($user, $role, $context);
        });

        // @hasanyrole directive
        Blade::if('hasanyrole', static function ($roles, $context = null) {
            $user = auth()->user();

            if (! $user) {
                return false;
            }

            $rolesArray = is_array($roles) ? $roles : explode('|', $roles);
            $compatibility = app(SpatieCompatibility::class);

            return $compatibility->hasAnyRole($user, $rolesArray, $context);
        });

        // @hasallroles directive
        Blade::if('hasallroles', static function ($roles, $context = null) {
            $user = auth()->user();

            if (! $user) {
                return false;
            }

            $rolesArray = is_array($roles) ? $roles : explode('|', $roles);
            $compatibility = app(SpatieCompatibility::class);

            return $compatibility->hasAllRoles($user, $rolesArray, $context);
        });

        // @haspermission directive
        Blade::if('haspermission', static function ($permission, $model = null) {
            $user = auth()->user();

            if (! $user) {
                return false;
            }

            $compatibility = app(SpatieCompatibility::class);

            return $compatibility->hasPermissionTo($user, $permission, $model);
        });

        // @hasanypermission directive
        Blade::if('hasanypermission', static function ($permissions, $model = null) {
            $user = auth()->user();

            if (! $user) {
                return false;
            }

            $permissionsArray = is_array($permissions) ? $permissions : explode('|', $permissions);
            $compatibility = app(SpatieCompatibility::class);

            return $compatibility->hasAnyPermission($user, $permissionsArray, $model);
        });

        // @hasallpermissions directive
        Blade::if('hasallpermissions', static function ($permissions, $model = null) {
            $user = auth()->user();

            if (! $user) {
                return false;
            }

            $permissionsArray = is_array($permissions) ? $permissions : explode('|', $permissions);
            $compatibility = app(SpatieCompatibility::class);

            return $compatibility->hasAllPermissions($user, $permissionsArray, $model);
        });

        // @unlessrole directive
        Blade::if('unlessrole', static function ($role, $context = null): bool {
            $user = auth()->user();

            if (! $user) {
                return true;
            }

            $compatibility = app(SpatieCompatibility::class);

            return ! $compatibility->hasRole($user, $role, $context);
        });

        // @unlesspermission directive
        Blade::if('unlesspermission', static function ($permission, $model = null): bool {
            $user = auth()->user();

            if (! $user) {
                return true;
            }

            $compatibility = app(SpatieCompatibility::class);

            return ! $compatibility->hasPermissionTo($user, $permission, $model);
        });

        // Enhanced @role directive with guard support
        Blade::if('role', static function ($role, $guard = null, $context = null) {
            $user = auth($guard)->user();

            if (! $user) {
                return false;
            }

            $compatibility = app(SpatieCompatibility::class);

            return $compatibility->hasRole($user, $role, $context);
        });

        // Enhanced @permission directive with guard support
        Blade::if('permission', static function ($permission, $guard = null, $model = null) {
            $user = auth($guard)->user();

            if (! $user) {
                return false;
            }

            $compatibility = app(SpatieCompatibility::class);

            return $compatibility->hasPermissionTo($user, $permission, $model);
        });
    }
}
