<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Providers;

use Illuminate\Contracts\Auth\{Guard};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use Override;

use function is_array;
use function is_scalar;
use function is_string;

/**
 * Service provider for Spatie-compatible Blade directives.
 *
 * @internal
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
        Blade::if('hasrole', static function (mixed $role, mixed $context = null): bool {
            $user = auth()->guard()->user();

            if (! $user instanceof Model) {
                return false;
            }

            $compatibility = app(SpatieCompatibility::class);

            $roleString = is_string($role) ? $role : '';
            $contextString = is_string($context) ? $context : null;

            return $compatibility->hasRole($user, $roleString, $contextString);
        });

        // @hasanyrole directive
        Blade::if('hasanyrole', static function (mixed $roles, mixed $context = null): bool {
            $user = auth()->guard()->user();

            if (! $user instanceof Model) {
                return false;
            }

            if (is_array($roles)) {
                /** @var array<int|string, string> $rolesArray */
                $rolesArray = array_map(static fn ($r): string => is_scalar($r) ? (string) $r : '', $roles);
            } else {
                $rolesArray = explode('|', is_scalar($roles) ? (string) $roles : '');
            }

            $compatibility = app(SpatieCompatibility::class);

            $contextString = is_string($context) ? $context : null;

            return $compatibility->hasAnyRole($user, $rolesArray, $contextString);
        });

        // @hasallroles directive
        Blade::if('hasallroles', static function (mixed $roles, mixed $context = null): bool {
            $user = auth()->guard()->user();

            if (! $user instanceof Model) {
                return false;
            }

            if (is_array($roles)) {
                /** @var array<int|string, string> $rolesArray */
                $rolesArray = array_map(static fn ($r): string => is_scalar($r) ? (string) $r : '', $roles);
            } else {
                $rolesArray = explode('|', is_scalar($roles) ? (string) $roles : '');
            }

            $compatibility = app(SpatieCompatibility::class);

            $contextString = is_string($context) ? $context : null;

            return $compatibility->hasAllRoles($user, $rolesArray, $contextString);
        });

        // @haspermission directive
        Blade::if('haspermission', static function (mixed $permission, mixed $model = null): bool {
            $user = auth()->guard()->user();

            if (! $user instanceof Model) {
                return false;
            }

            $compatibility = app(SpatieCompatibility::class);

            $permissionString = is_string($permission) ? $permission : '';
            $modelParam = $model instanceof Model ? $model : null;

            return $compatibility->hasPermissionTo($user, $permissionString, $modelParam);
        });

        // @hasanypermission directive
        Blade::if('hasanypermission', static function (mixed $permissions, mixed $model = null): bool {
            $user = auth()->guard()->user();

            if (! $user instanceof Model) {
                return false;
            }

            if (is_array($permissions)) {
                /** @var array<int|string, string> $permissionsArray */
                $permissionsArray = array_map(static fn ($p): string => is_scalar($p) ? (string) $p : '', $permissions);
            } else {
                $permissionsArray = explode('|', is_scalar($permissions) ? (string) $permissions : '');
            }

            $compatibility = app(SpatieCompatibility::class);

            $modelParam = $model instanceof Model ? $model : null;

            return $compatibility->hasAnyPermission($user, $permissionsArray, $modelParam);
        });

        // @hasallpermissions directive
        Blade::if('hasallpermissions', static function (mixed $permissions, mixed $model = null): bool {
            $user = auth()->guard()->user();

            if (! $user instanceof Model) {
                return false;
            }

            if (is_array($permissions)) {
                /** @var array<int|string, string> $permissionsArray */
                $permissionsArray = array_map(static fn ($p): string => is_scalar($p) ? (string) $p : '', $permissions);
            } else {
                $permissionsArray = explode('|', is_scalar($permissions) ? (string) $permissions : '');
            }

            $compatibility = app(SpatieCompatibility::class);

            $modelParam = $model instanceof Model ? $model : null;

            return $compatibility->hasAllPermissions($user, $permissionsArray, $modelParam);
        });

        // @unlessrole directive
        Blade::if('unlessrole', static function (mixed $role, mixed $context = null): bool {
            $user = auth()->guard()->user();

            if (! $user instanceof Model) {
                return true;
            }

            $compatibility = app(SpatieCompatibility::class);

            $roleString = is_string($role) ? $role : '';
            $contextString = is_string($context) ? $context : null;

            return ! $compatibility->hasRole($user, $roleString, $contextString);
        });

        // @unlesspermission directive
        Blade::if('unlesspermission', static function (mixed $permission, mixed $model = null): bool {
            $user = auth()->guard()->user();

            if (! $user instanceof Model) {
                return true;
            }

            $compatibility = app(SpatieCompatibility::class);

            $permissionString = is_string($permission) ? $permission : '';
            $modelParam = $model instanceof Model ? $model : null;

            return ! $compatibility->hasPermissionTo($user, $permissionString, $modelParam);
        });

        // Enhanced @role directive with guard support
        Blade::if('role', static function (mixed $role, mixed $guard = null, mixed $context = null): bool {
            $authFactory = auth();
            $auth = is_string($guard) ? $authFactory->guard($guard) : $authFactory->guard();
            $user = $auth->user();

            if (! $user instanceof Model) {
                return false;
            }

            $compatibility = app(SpatieCompatibility::class);

            $roleString = is_string($role) ? $role : '';
            $contextString = is_string($context) ? $context : null;

            return $compatibility->hasRole($user, $roleString, $contextString);
        });

        // Enhanced @permission directive with guard support
        Blade::if('permission', static function (mixed $permission, mixed $guard = null, mixed $model = null): bool {
            $authFactory = auth();
            $auth = is_string($guard) ? $authFactory->guard($guard) : $authFactory->guard();
            $user = $auth->user();

            if (! $user instanceof Model) {
                return false;
            }

            $compatibility = app(SpatieCompatibility::class);

            $permissionString = is_string($permission) ? $permission : '';
            $modelParam = $model instanceof Model ? $model : null;

            return $compatibility->hasPermissionTo($user, $permissionString, $modelParam);
        });
    }
}
