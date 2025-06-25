<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;

/**
 * Trait to add Spatie Laravel Permission compatible methods to User models
 * 
 * This trait allows existing code using Spatie syntax to work with OpenFGA
 * with minimal changes.
 */
trait SpatieCompatible
{
    /**
     * Get the Spatie compatibility service
     */
    protected function getSpatieCompatibility(): SpatieCompatibility
    {
        return app(SpatieCompatibility::class);
    }

    /**
     * Check if user has permission
     */
    public function hasPermissionTo(string $permission, ?Model $model = null): bool
    {
        return $this->getSpatieCompatibility()->hasPermissionTo($this, $permission, $model);
    }

    /**
     * Check if user has any of the given permissions
     */
    public function hasAnyPermission(array $permissions, ?Model $model = null): bool
    {
        return $this->getSpatieCompatibility()->hasAnyPermission($this, $permissions, $model);
    }

    /**
     * Check if user has all of the given permissions
     */
    public function hasAllPermissions(array $permissions, ?Model $model = null): bool
    {
        return $this->getSpatieCompatibility()->hasAllPermissions($this, $permissions, $model);
    }

    /**
     * Check if user has role
     */
    public function hasRole(string $role, ?string $context = null): bool
    {
        return $this->getSpatieCompatibility()->hasRole($this, $role, $context);
    }

    /**
     * Check if user has any of the given roles
     */
    public function hasAnyRole(array $roles, ?string $context = null): bool
    {
        return $this->getSpatieCompatibility()->hasAnyRole($this, $roles, $context);
    }

    /**
     * Check if user has all of the given roles
     */
    public function hasAllRoles(array $roles, ?string $context = null): bool
    {
        return $this->getSpatieCompatibility()->hasAllRoles($this, $roles, $context);
    }

    /**
     * Assign role to user
     */
    public function assignRole(string $role, ?string $context = null): self
    {
        $this->getSpatieCompatibility()->assignRole($this, $role, $context);
        return $this;
    }

    /**
     * Remove role from user
     */
    public function removeRole(string $role, ?string $context = null): self
    {
        $this->getSpatieCompatibility()->removeRole($this, $role, $context);
        return $this;
    }

    /**
     * Give permission to user
     */
    public function givePermissionTo(string $permission, ?Model $model = null): self
    {
        $this->getSpatieCompatibility()->givePermissionTo($this, $permission, $model);
        return $this;
    }

    /**
     * Revoke permission from user
     */
    public function revokePermissionTo(string $permission, ?Model $model = null): self
    {
        $this->getSpatieCompatibility()->revokePermissionTo($this, $permission, $model);
        return $this;
    }

    /**
     * Get all permissions for user
     */
    public function getAllPermissions(?string $context = null): Collection
    {
        return $this->getSpatieCompatibility()->getAllPermissions($this, $context);
    }

    /**
     * Get all role names for user
     */
    public function getRoleNames(?string $context = null): Collection
    {
        return $this->getSpatieCompatibility()->getRoleNames($this, $context);
    }

    /**
     * Sync roles for user
     */
    public function syncRoles(array $roles, ?string $context = null): self
    {
        $this->getSpatieCompatibility()->syncRoles($this, $roles, $context);
        return $this;
    }

    /**
     * Sync permissions for user
     */
    public function syncPermissions(array $permissions, ?Model $model = null): self
    {
        $this->getSpatieCompatibility()->syncPermissions($this, $permissions, $model);
        return $this;
    }

    /**
     * Alias for hasPermissionTo for better Spatie compatibility
     */
    public function can(string $permission, ?Model $model = null): bool
    {
        return $this->hasPermissionTo($permission, $model);
    }

    /**
     * Alias for !hasPermissionTo for better Spatie compatibility
     */
    public function cannot(string $permission, ?Model $model = null): bool
    {
        return !$this->hasPermissionTo($permission, $model);
    }

    /**
     * Check if user has direct permission (without inheritance)
     * 
     * Note: In OpenFGA, all permissions can be inherited through relationships,
     * so this is an approximation for compatibility.
     */
    public function hasDirectPermission(string $permission, ?Model $model = null): bool
    {
        // In OpenFGA context, we treat this the same as hasPermissionTo
        // In a real implementation, you might want to check only direct tuples
        return $this->hasPermissionTo($permission, $model);
    }

    /**
     * Get direct permissions (without inheritance)
     * 
     * Note: This is a simplified implementation for compatibility.
     */
    public function getDirectPermissions(?string $context = null): Collection
    {
        // In a full implementation, you would query only direct relationships
        return $this->getAllPermissions($context);
    }

    /**
     * Get permissions via roles
     * 
     * Note: This is a simplified implementation for compatibility.
     */
    public function getPermissionsViaRoles(?string $context = null): Collection
    {
        // In OpenFGA, all permissions are relationship-based
        // This is more of a conceptual difference
        return collect(); // Return empty for now, could be enhanced
    }

    /**
     * Spatie-style method aliases for better compatibility
     */

    /**
     * Check if user has exact roles
     */
    public function hasExactRoles(array $roles, ?string $context = null): bool
    {
        $userRoles = $this->getRoleNames($context)->sort()->values();
        $checkRoles = collect($roles)->sort()->values();
        
        return $userRoles->equals($checkRoles);
    }

    /**
     * Get roles collection (compatibility method)
     */
    public function roles(?string $context = null): Collection
    {
        return $this->getRoleNames($context)->map(function ($roleName) {
            return (object) ['name' => $roleName];
        });
    }

    /**
     * Get permissions collection (compatibility method)
     */
    public function permissions(?string $context = null): Collection
    {
        return $this->getAllPermissions($context)->map(function ($permissionName) {
            return (object) ['name' => $permissionName];
        });
    }
}