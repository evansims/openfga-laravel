<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Compatibility;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Contracts\{AuthorizationUser, ManagerInterface};
use OpenFGA\Laravel\Facades\OpenFga;

use function in_array;
use function is_scalar;

/**
 * Spatie Laravel Permission compatibility layer.
 *
 * This class provides familiar Spatie syntax while using OpenFGA under the hood.
 * Allows for gradual migration from Spatie to OpenFGA.
 */
final class SpatieCompatibility
{
    /**
     * Permission to relation mapping for common Spatie permissions.
     *
     * @var array<string, string>
     */
    private array $permissionMapping = [
        'edit posts' => 'editor',
        'view posts' => 'viewer',
        'delete posts' => 'owner',
        'manage users' => 'admin',
        'view admin panel' => 'admin',
        'edit articles' => 'editor',
        'view articles' => 'viewer',
        'delete articles' => 'owner',
    ];

    /**
     * Role to relation mapping for common Spatie roles.
     *
     * @var array<string, string>
     */
    private array $roleMapping = [
        'admin' => 'admin',
        'editor' => 'editor',
        'moderator' => 'moderator',
        'user' => 'member',
        'guest' => 'viewer',
    ];

    /**
     * Add custom permission mapping.
     *
     * @param string $permission
     * @param string $relation
     */
    public function addPermissionMapping(string $permission, string $relation): void
    {
        $this->permissionMapping[$permission] = $relation;
    }

    /**
     * Add custom role mapping.
     *
     * @param string $role
     * @param string $relation
     */
    public function addRoleMapping(string $role, string $relation): void
    {
        $this->roleMapping[$role] = $relation;
    }

    /**
     * Assign role to user (Spatie-style).
     *
     * @param Model   $user
     * @param string  $role
     * @param ?string $context
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function assignRole(Model $user, string $role, ?string $context = null): void
    {
        $relation = $this->mapRoleToRelation($role);
        $object = $context ?? 'organization:main';

        if ($user instanceof AuthorizationUser) {
            $userId = $user->authorizationUser();
        } else {
            /** @var mixed $key */
            $key = $user->getKey();
            $userId = 'user:' . (is_scalar($key) ? (string) $key : '');
        }

        $manager = app(ManagerInterface::class);
        $manager->grant($userId, $relation, $object);
    }

    /**
     * Get all permissions for user (simulated).
     *
     * @param  Model                   $user
     * @param  ?string                 $context
     * @return Collection<int, string>
     *
     * @phpstan-return Collection<int, string>
     *
     * @psalm-return Collection<int<0, max>, string>
     */
    public function getAllPermissions(Model $user, ?string $context = null): Collection
    {
        // This is a simplified version - in practice, you'd use OpenFGA's expand API
        $permissions = [];

        foreach (array_keys($this->permissionMapping) as $permission) {
            if ($this->hasPermissionTo($user, $permission)) {
                $permissions[] = $permission;
            }
        }

        return collect($permissions);
    }

    /**
     * Get current permission mappings.
     *
     * @return array<string, string>
     */
    public function getPermissionMappings(): array
    {
        return $this->permissionMapping;
    }

    /**
     * Get current role mappings.
     *
     * @return array<string, string>
     */
    public function getRoleMappings(): array
    {
        return $this->roleMapping;
    }

    /**
     * Get all roles for user (simulated).
     *
     * @param  Model                   $user
     * @param  ?string                 $context
     * @return Collection<int, string>
     *
     * @phpstan-return Collection<int, string>
     *
     * @psalm-return Collection<int<0, max>, string>
     */
    public function getRoleNames(Model $user, ?string $context = null): Collection
    {
        $roles = [];

        foreach (array_keys($this->roleMapping) as $role) {
            if ($this->hasRole($user, $role, $context)) {
                $roles[] = $role;
            }
        }

        return collect($roles);
    }

    /**
     * Give permission to user (Spatie-style).
     *
     * @param Model  $user
     * @param string $permission
     * @param ?Model $model
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function givePermissionTo(Model $user, string $permission, ?Model $model = null): void
    {
        $relation = $this->mapPermissionToRelation($permission);

        /** @var string $object */
        $object = $model instanceof Model && method_exists($model, 'authorizationObject') ? $model->authorizationObject() : $this->getDefaultObject($permission);

        if ($user instanceof AuthorizationUser) {
            $userId = $user->authorizationUser();
        } else {
            /** @var mixed $key */
            $key = $user->getKey();
            $userId = 'user:' . (is_scalar($key) ? (string) $key : '');
        }

        $manager = app(ManagerInterface::class);
        $manager->grant($userId, $relation, $object);
    }

    /**
     * Check if user has all of the given permissions.
     *
     * @param Model                     $user
     * @param array<int|string, string> $permissions
     * @param ?Model                    $model
     */
    public function hasAllPermissions(Model $user, array $permissions, ?Model $model = null): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermissionTo($user, $permission, $model)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if user has all of the given roles.
     *
     * @param Model                     $user
     * @param array<int|string, string> $roles
     * @param ?string                   $context
     */
    public function hasAllRoles(Model $user, array $roles, ?string $context = null): bool
    {
        foreach ($roles as $role) {
            if (! $this->hasRole($user, $role, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if user has any of the given permissions.
     *
     * @param Model                     $user
     * @param array<int|string, string> $permissions
     * @param ?Model                    $model
     */
    public function hasAnyPermission(Model $user, array $permissions, ?Model $model = null): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($user, $permission, $model)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has any of the given roles.
     *
     * @param Model                     $user
     * @param array<int|string, string> $roles
     * @param ?string                   $context
     */
    public function hasAnyRole(Model $user, array $roles, ?string $context = null): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($user, $role, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has permission (Spatie-style).
     *
     * @param Model  $user
     * @param string $permission
     * @param ?Model $model
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function hasPermissionTo(Model $user, string $permission, ?Model $model = null): bool
    {
        $relation = $this->mapPermissionToRelation($permission);

        /** @var string $object */
        $object = $model instanceof Model && method_exists($model, 'authorizationObject') ? $model->authorizationObject() : $this->getDefaultObject($permission);

        if ($user instanceof AuthorizationUser) {
            $userId = $user->authorizationUser();
        } else {
            /** @var mixed $key */
            $key = $user->getKey();
            $userId = 'user:' . (is_scalar($key) ? (string) $key : '');
        }

        $manager = app(ManagerInterface::class);

        return $manager->check($userId, $relation, $object);
    }

    /**
     * Check if user has role (Spatie-style).
     *
     * @param Model   $user
     * @param string  $role
     * @param ?string $context
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function hasRole(Model $user, string $role, ?string $context = null): bool
    {
        $relation = $this->mapRoleToRelation($role);
        $object = $context ?? 'organization:main';

        if ($user instanceof AuthorizationUser) {
            $userId = $user->authorizationUser();
        } else {
            /** @var mixed $key */
            $key = $user->getKey();
            $userId = 'user:' . (is_scalar($key) ? (string) $key : '');
        }

        $manager = app(ManagerInterface::class);

        return $manager->check($userId, $relation, $object);
    }

    /**
     * Remove role from user.
     *
     * @param Model   $user
     * @param string  $role
     * @param ?string $context
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function removeRole(Model $user, string $role, ?string $context = null): void
    {
        $relation = $this->mapRoleToRelation($role);
        $object = $context ?? 'organization:main';

        if ($user instanceof AuthorizationUser) {
            $userId = $user->authorizationUser();
        } else {
            /** @var mixed $key */
            $key = $user->getKey();
            $userId = 'user:' . (is_scalar($key) ? (string) $key : '');
        }

        $manager = app(ManagerInterface::class);
        $manager->revoke($userId, $relation, $object);
    }

    /**
     * Revoke permission from user.
     *
     * @param Model  $user
     * @param string $permission
     * @param ?Model $model
     *
     * @throws BindingResolutionException|ClientThrowable|Exception|InvalidArgumentException
     */
    public function revokePermissionTo(Model $user, string $permission, ?Model $model = null): void
    {
        $relation = $this->mapPermissionToRelation($permission);

        /** @var string $object */
        $object = $model instanceof Model && method_exists($model, 'authorizationObject') ? $model->authorizationObject() : $this->getDefaultObject($permission);

        if ($user instanceof AuthorizationUser) {
            $userId = $user->authorizationUser();
        } else {
            /** @var mixed $key */
            $key = $user->getKey();
            $userId = 'user:' . (is_scalar($key) ? (string) $key : '');
        }

        $manager = app(ManagerInterface::class);
        $manager->revoke($userId, $relation, $object);
    }

    /**
     * Sync permissions for user.
     *
     * @param Model                     $user
     * @param array<int|string, string> $permissions
     * @param ?Model                    $model
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function syncPermissions(Model $user, array $permissions, ?Model $model = null): void
    {
        $currentPermissions = $this->getAllPermissions($user);

        // Remove permissions not in the new list
        foreach ($currentPermissions as $currentPermission) {
            if (! in_array($currentPermission, $permissions, true)) {
                $this->revokePermissionTo($user, $currentPermission, $model);
            }
        }

        // Add new permissions
        foreach ($permissions as $permission) {
            if (! $currentPermissions->contains($permission)) {
                $this->givePermissionTo($user, $permission, $model);
            }
        }
    }

    /**
     * Sync roles for user.
     *
     * @param Model                     $user
     * @param array<int|string, string> $roles
     * @param ?string                   $context
     */
    public function syncRoles(Model $user, array $roles, ?string $context = null): void
    {
        $currentRoles = $this->getRoleNames($user, $context);

        // Remove roles not in the new list
        foreach ($currentRoles as $currentRole) {
            if (! in_array($currentRole, $roles, true)) {
                $this->removeRole($user, $currentRole, $context);
            }
        }

        // Add new roles
        foreach ($roles as $role) {
            if (! $currentRoles->contains($role)) {
                $this->assignRole($user, $role, $context);
            }
        }
    }

    /**
     * Get default object for permission when no model is provided.
     *
     * @param string $permission
     */
    private function getDefaultObject(string $permission): string
    {
        // Extract resource type from permission
        if (str_contains($permission, 'post')) {
            return 'post:*';
        }

        if (str_contains($permission, 'article')) {
            return 'article:*';
        }

        // Default to organization context
        return 'organization:main';
    }

    /**
     * Map Spatie permission to OpenFGA relation.
     *
     * @param string $permission
     */
    private function mapPermissionToRelation(string $permission): string
    {
        if (isset($this->permissionMapping[$permission])) {
            return $this->permissionMapping[$permission];
        }

        // Try to infer relation from permission name
        if (str_contains($permission, 'edit') || str_contains($permission, 'update')) {
            return 'editor';
        }

        if (str_contains($permission, 'delete') || str_contains($permission, 'destroy')) {
            return 'owner';
        }

        if (str_contains($permission, 'view') || str_contains($permission, 'read')) {
            return 'viewer';
        }

        if (str_contains($permission, 'manage') || str_contains($permission, 'admin')) {
            return 'admin';
        }

        // Default fallback
        return 'member';
    }

    /**
     * Map Spatie role to OpenFGA relation.
     *
     * @param string $role
     */
    private function mapRoleToRelation(string $role): string
    {
        return $this->roleMapping[$role] ?? $role;
    }
}
