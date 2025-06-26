<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;

/**
 * Compatibility layer for migrating from Spatie Laravel Permission to OpenFGA.
 *
 * This trait provides familiar Spatie methods on your User model, translating
 * them to OpenFGA operations behind the scenes. It eases migration by allowing
 * existing code to work with minimal changes while you transition to OpenFGA's
 * relationship-based model. Supports role assignment, permission checking, and
 * synchronization operations using Spatie's API conventions.
 *
 * @api
 */
trait SpatieCompatible
{
    /**
     * Assign role to user.
     *
     * @param string  $role
     * @param ?string $context
     */
    public function assignRole(string $role, ?string $context = null): self
    {
        $this->getSpatieCompatibility()->assignRole($this, $role, $context);

        return $this;
    }

    /**
     * Alias for hasPermissionTo for better Spatie compatibility.
     *
     * @param string $permission
     * @param ?Model $model
     */
    public function can(string $permission, ?Model $model = null): bool
    {
        return $this->hasPermissionTo($permission, $model);
    }

    /**
     * Alias for !hasPermissionTo for better Spatie compatibility.
     *
     * @param string $permission
     * @param ?Model $model
     */
    public function cannot(string $permission, ?Model $model = null): bool
    {
        return ! $this->hasPermissionTo($permission, $model);
    }

    /**
     * Get all permissions for user.
     *
     * @param ?string $context
     */
    public function getAllPermissions(?string $context = null): Collection
    {
        return $this->getSpatieCompatibility()->getAllPermissions($this, $context);
    }

    /**
     * Get direct permissions (without inheritance).
     *
     * Note: This is a simplified implementation for compatibility.
     *
     * @param ?string $context
     */
    public function getDirectPermissions(?string $context = null): Collection
    {
        // In a full implementation, you would query only direct relationships
        return $this->getAllPermissions($context);
    }

    /**
     * Get permissions via roles.
     *
     * Note: This is a simplified implementation for compatibility.
     *
     * @param ?string $context
     */
    public function getPermissionsViaRoles(?string $context = null): Collection
    {
        // In OpenFGA, all permissions are relationship-based
        // This is more of a conceptual difference
        return collect(); // Return empty for now, could be enhanced
    }

    /**
     * Get all role names for user.
     *
     * @param ?string $context
     */
    public function getRoleNames(?string $context = null): Collection
    {
        return $this->getSpatieCompatibility()->getRoleNames($this, $context);
    }

    /**
     * Give permission to user.
     *
     * @param string $permission
     * @param ?Model $model
     */
    public function givePermissionTo(string $permission, ?Model $model = null): self
    {
        $this->getSpatieCompatibility()->givePermissionTo($this, $permission, $model);

        return $this;
    }

    /**
     * Check if user has all of the given permissions.
     *
     * @param array  $permissions
     * @param ?Model $model
     */
    public function hasAllPermissions(array $permissions, ?Model $model = null): bool
    {
        return $this->getSpatieCompatibility()->hasAllPermissions($this, $permissions, $model);
    }

    /**
     * Check if user has all of the given roles.
     *
     * @param array   $roles
     * @param ?string $context
     */
    public function hasAllRoles(array $roles, ?string $context = null): bool
    {
        return $this->getSpatieCompatibility()->hasAllRoles($this, $roles, $context);
    }

    /**
     * Check if user has any of the given permissions.
     *
     * @param array  $permissions
     * @param ?Model $model
     */
    public function hasAnyPermission(array $permissions, ?Model $model = null): bool
    {
        return $this->getSpatieCompatibility()->hasAnyPermission($this, $permissions, $model);
    }

    /**
     * Check if user has any of the given roles.
     *
     * @param array   $roles
     * @param ?string $context
     */
    public function hasAnyRole(array $roles, ?string $context = null): bool
    {
        return $this->getSpatieCompatibility()->hasAnyRole($this, $roles, $context);
    }

    /**
     * Check if user has direct permission (without inheritance).
     *
     * Note: In OpenFGA, all permissions can be inherited through relationships,
     * so this is an approximation for compatibility.
     *
     * @param string $permission
     * @param ?Model $model
     */
    public function hasDirectPermission(string $permission, ?Model $model = null): bool
    {
        // In OpenFGA context, we treat this the same as hasPermissionTo
        // In a real implementation, you might want to check only direct tuples
        return $this->hasPermissionTo($permission, $model);
    }

    /**
     * Spatie-style method aliases for better compatibility.
     *
     * @param array   $roles
     * @param ?string $context
     */

    /**
     * Check if user has exact roles.
     */
    public function hasExactRoles(array $roles, ?string $context = null): bool
    {
        $userRoles = $this->getRoleNames($context)->sort()->values();
        $checkRoles = collect($roles)->sort()->values();

        return $userRoles->equals($checkRoles);
    }

    /**
     * Check if user has permission.
     *
     * @param string $permission
     * @param ?Model $model
     */
    public function hasPermissionTo(string $permission, ?Model $model = null): bool
    {
        return $this->getSpatieCompatibility()->hasPermissionTo($this, $permission, $model);
    }

    /**
     * Check if user has role.
     *
     * @param string  $role
     * @param ?string $context
     */
    public function hasRole(string $role, ?string $context = null): bool
    {
        return $this->getSpatieCompatibility()->hasRole($this, $role, $context);
    }

    /**
     * Get permissions collection (compatibility method).
     *
     * @param ?string $context
     */
    public function permissions(?string $context = null): Collection
    {
        return $this->getAllPermissions($context)->map(static fn ($permissionName) => (object) ['name' => $permissionName]);
    }

    /**
     * Remove role from user.
     *
     * @param string  $role
     * @param ?string $context
     */
    public function removeRole(string $role, ?string $context = null): self
    {
        $this->getSpatieCompatibility()->removeRole($this, $role, $context);

        return $this;
    }

    /**
     * Revoke permission from user.
     *
     * @param string $permission
     * @param ?Model $model
     */
    public function revokePermissionTo(string $permission, ?Model $model = null): self
    {
        $this->getSpatieCompatibility()->revokePermissionTo($this, $permission, $model);

        return $this;
    }

    /**
     * Get roles collection (compatibility method).
     *
     * @param ?string $context
     */
    public function roles(?string $context = null): Collection
    {
        return $this->getRoleNames($context)->map(static fn ($roleName) => (object) ['name' => $roleName]);
    }

    /**
     * Sync permissions for user.
     *
     * @param array  $permissions
     * @param ?Model $model
     */
    public function syncPermissions(array $permissions, ?Model $model = null): self
    {
        $this->getSpatieCompatibility()->syncPermissions($this, $permissions, $model);

        return $this;
    }

    /**
     * Sync roles for user.
     *
     * @param array   $roles
     * @param ?string $context
     */
    public function syncRoles(array $roles, ?string $context = null): self
    {
        $this->getSpatieCompatibility()->syncRoles($this, $roles, $context);

        return $this;
    }

    /**
     * Get the Spatie compatibility service.
     */
    protected function getSpatieCompatibility(): SpatieCompatibility
    {
        return app(SpatieCompatibility::class);
    }
}
