<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Compatibility;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenFGA\Laravel\Facades\OpenFga;

/**
 * Spatie Laravel Permission compatibility layer
 * 
 * This class provides familiar Spatie syntax while using OpenFGA under the hood.
 * Allows for gradual migration from Spatie to OpenFGA.
 */
class SpatieCompatibility
{
    /**
     * Permission to relation mapping for common Spatie permissions
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
     * Role to relation mapping for common Spatie roles
     */
    private array $roleMapping = [
        'admin' => 'admin',
        'editor' => 'editor',
        'moderator' => 'moderator',
        'user' => 'member',
        'guest' => 'viewer',
    ];

    /**
     * Check if user has permission (Spatie-style)
     */
    public function hasPermissionTo(Model $user, string $permission, ?Model $model = null): bool
    {
        $relation = $this->mapPermissionToRelation($permission);
        $object = $model ? $model->authorizationObject() : $this->getDefaultObject($permission);

        return OpenFga::check($user->authorizationUser(), $relation, $object);
    }

    /**
     * Check if user has any of the given permissions
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
     * Check if user has all of the given permissions
     */
    public function hasAllPermissions(Model $user, array $permissions, ?Model $model = null): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermissionTo($user, $permission, $model)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if user has role (Spatie-style)
     */
    public function hasRole(Model $user, string $role, ?string $context = null): bool
    {
        $relation = $this->mapRoleToRelation($role);
        $object = $context ?? 'organization:main';

        return OpenFga::check($user->authorizationUser(), $relation, $object);
    }

    /**
     * Check if user has any of the given roles
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
     * Check if user has all of the given roles
     */
    public function hasAllRoles(Model $user, array $roles, ?string $context = null): bool
    {
        foreach ($roles as $role) {
            if (!$this->hasRole($user, $role, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Assign role to user (Spatie-style)
     */
    public function assignRole(Model $user, string $role, ?string $context = null): void
    {
        $relation = $this->mapRoleToRelation($role);
        $object = $context ?? 'organization:main';

        OpenFga::grant($user->authorizationUser(), $relation, $object);
    }

    /**
     * Remove role from user
     */
    public function removeRole(Model $user, string $role, ?string $context = null): void
    {
        $relation = $this->mapRoleToRelation($role);
        $object = $context ?? 'organization:main';

        OpenFga::revoke($user->authorizationUser(), $relation, $object);
    }

    /**
     * Give permission to user (Spatie-style)
     */
    public function givePermissionTo(Model $user, string $permission, ?Model $model = null): void
    {
        $relation = $this->mapPermissionToRelation($permission);
        $object = $model ? $model->authorizationObject() : $this->getDefaultObject($permission);

        OpenFga::grant($user->authorizationUser(), $relation, $object);
    }

    /**
     * Revoke permission from user
     */
    public function revokePermissionTo(Model $user, string $permission, ?Model $model = null): void
    {
        $relation = $this->mapPermissionToRelation($permission);
        $object = $model ? $model->authorizationObject() : $this->getDefaultObject($permission);

        OpenFga::revoke($user->authorizationUser(), $relation, $object);
    }

    /**
     * Get all permissions for user (simulated)
     */
    public function getAllPermissions(Model $user, ?string $context = null): Collection
    {
        // This is a simplified version - in practice, you'd use OpenFGA's expand API
        $permissions = collect();
        $object = $context ?? 'organization:main';

        foreach (array_keys($this->permissionMapping) as $permission) {
            if ($this->hasPermissionTo($user, $permission)) {
                $permissions->push($permission);
            }
        }

        return $permissions;
    }

    /**
     * Get all roles for user (simulated)
     */
    public function getRoleNames(Model $user, ?string $context = null): Collection
    {
        $roles = collect();
        
        foreach (array_keys($this->roleMapping) as $role) {
            if ($this->hasRole($user, $role, $context)) {
                $roles->push($role);
            }
        }

        return $roles;
    }

    /**
     * Sync roles for user
     */
    public function syncRoles(Model $user, array $roles, ?string $context = null): void
    {
        $currentRoles = $this->getRoleNames($user, $context);
        
        // Remove roles not in the new list
        foreach ($currentRoles as $currentRole) {
            if (!in_array($currentRole, $roles)) {
                $this->removeRole($user, $currentRole, $context);
            }
        }

        // Add new roles
        foreach ($roles as $role) {
            if (!$currentRoles->contains($role)) {
                $this->assignRole($user, $role, $context);
            }
        }
    }

    /**
     * Sync permissions for user
     */
    public function syncPermissions(Model $user, array $permissions, ?Model $model = null): void
    {
        $currentPermissions = $this->getAllPermissions($user);
        
        // Remove permissions not in the new list
        foreach ($currentPermissions as $currentPermission) {
            if (!in_array($currentPermission, $permissions)) {
                $this->revokePermissionTo($user, $currentPermission, $model);
            }
        }

        // Add new permissions
        foreach ($permissions as $permission) {
            if (!$currentPermissions->contains($permission)) {
                $this->givePermissionTo($user, $permission, $model);
            }
        }
    }

    /**
     * Map Spatie permission to OpenFGA relation
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
     * Map Spatie role to OpenFGA relation
     */
    private function mapRoleToRelation(string $role): string
    {
        return $this->roleMapping[$role] ?? $role;
    }

    /**
     * Get default object for permission when no model is provided
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

        if (str_contains($permission, 'user')) {
            return 'organization:main';
        }

        if (str_contains($permission, 'admin')) {
            return 'organization:main';
        }

        // Default to organization context
        return 'organization:main';
    }

    /**
     * Add custom permission mapping
     */
    public function addPermissionMapping(string $permission, string $relation): void
    {
        $this->permissionMapping[$permission] = $relation;
    }

    /**
     * Add custom role mapping
     */
    public function addRoleMapping(string $role, string $relation): void
    {
        $this->roleMapping[$role] = $relation;
    }

    /**
     * Get current permission mappings
     */
    public function getPermissionMappings(): array
    {
        return $this->permissionMapping;
    }

    /**
     * Get current role mappings
     */
    public function getRoleMappings(): array
    {
        return $this->roleMapping;
    }
}