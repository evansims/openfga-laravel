<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Log};
use OpenFGA\Laravel\Facades\OpenFga;
use stdClass;

use function count;
use function is_array;
use function is_scalar;
use function is_string;
use function sprintf;

/**
 * Command to synchronize Spatie Laravel Permission data with OpenFGA.
 * This command can be run periodically to keep permissions in sync.
 */
final class SyncSpatiePermissionsCommand extends Command
{
    /**
     * @var string
     */
    protected $description = 'Synchronize Spatie Laravel Permission data with OpenFGA';

    protected $signature = 'openfga:sync-spatie-permissions
                            {--dry-run : Show what would be synced without making changes}
                            {--batch-size=100 : Number of records to process at once}
                            {--full : Perform a full sync, removing OpenFGA permissions not in Spatie}
                            {--quiet-mode : Suppress detailed output}';

    private int $added = 0;

    private int $batchSize = 100;

    private bool $dryRun = false;

    private int $errors = 0;

    private bool $fullSync = false;

    private bool $quietMode = false;

    private int $removed = 0;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->fullSync = (bool) $this->option('full');
        $this->quietMode = (bool) $this->option('quiet-mode');
        $batchSize = $this->option('batch-size');
        $this->batchSize = is_numeric($batchSize) ? (int) $batchSize : 100;

        $this->info('🔄 Synchronizing Spatie Laravel Permission with OpenFGA');

        if ($this->dryRun) {
            $this->warn('🧪 Running in DRY-RUN mode - no changes will be made');
        }

        if ($this->fullSync) {
            $this->warn('⚠️  Running FULL SYNC - OpenFGA permissions not in Spatie will be removed');
        }

        if (! $this->checkSpatieTablesExist()) {
            $this->error('❌ Spatie Laravel Permission tables not found. Is it installed?');

            return 1;
        }

        try {
            // Sync roles and their permissions
            $this->syncRoles();

            // Sync direct user permissions
            $this->syncUserPermissions();

            // Sync user roles
            $this->syncUserRoles();

            // If full sync, remove OpenFGA permissions not in Spatie
            if ($this->fullSync && ! $this->dryRun) {
                $this->removeOrphanedPermissions();
            }

            $this->displaySummary();
        } catch (Exception $exception) {
            $this->error('❌ Sync failed: ' . $exception->getMessage());
            Log::error('Spatie sync failed', ['exception' => $exception]);

            return 1;
        }

        return 0;
    }

    private function checkSpatieTablesExist(): bool
    {
        $tables = config('spatie-compatibility.migration.tables', [
            'roles' => 'roles',
            'permissions' => 'permissions',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'role_has_permissions' => 'role_has_permissions',
        ]);

        if (! is_array($tables)) {
            return false;
        }

        /** @var string $table */
        foreach ($tables as $table) {
            try {
                DB::table($table)->limit(1)->get();
            } catch (QueryException) {
                return false;
            }
        }

        return true;
    }

    private function displaySummary(): void
    {
        $this->newLine();
        $this->info('📊 Sync Summary:');

        $status = 0 === $this->errors ? '✅ Success' : '⚠️  Completed with errors';

        $this->table(
            ['Metric', 'Count', 'Status'],
            [
                ['Permissions Added', $this->added, 0 < $this->added ? '✅' : '➖'],
                ['Permissions Removed', $this->removed, 0 < $this->removed ? '✅' : '➖'],
                ['Errors', $this->errors, 0 === $this->errors ? '✅' : '❌'],
                ['Overall Status', '-', $status],
            ],
        );

        if ($this->dryRun) {
            $this->newLine();
            $this->warn('🧪 This was a dry run. No changes were made.');
            $this->info('Run without --dry-run to apply changes.');
        }
    }

    private function mapPermissionToObject(string $permission): string
    {
        /** @var mixed $resourceMappings */
        $resourceMappings = config('spatie-compatibility.resource_mappings', []);

        if (is_array($resourceMappings)) {
            /**
             * @var int|string $resource
             * @var mixed      $objectType
             */
            foreach ($resourceMappings as $resource => $objectType) {
                if (is_string($resource) && is_string($objectType) && str_contains($permission, $resource)) {
                    return $objectType . ':*';
                }
            }
        }

        /** @var mixed $defaultContext */
        $defaultContext = config('spatie-compatibility.default_context', 'organization:main');

        return is_string($defaultContext) ? $defaultContext : 'organization:main';
    }

    private function mapPermissionToRelation(string $permission): string
    {
        /** @var mixed $mappings */
        $mappings = config('spatie-compatibility.permission_mappings', []);

        if (is_array($mappings) && isset($mappings[$permission]) && is_string($mappings[$permission])) {
            return $mappings[$permission];
        }

        // Try to infer from permission name
        /** @var mixed $rules */
        $rules = config('spatie-compatibility.inference_rules', []);

        if (is_array($rules)) {
            /** @var mixed $ownerActions */
            $ownerActions = $rules['owner_actions'] ?? [];

            if (is_array($ownerActions)) {
                /** @var mixed $ownerAction */
                foreach ($ownerActions as $ownerAction) {
                    if (is_string($ownerAction) && str_contains($permission, $ownerAction)) {
                        return 'owner';
                    }
                }
            }

            /** @var mixed $editorActions */
            $editorActions = $rules['editor_actions'] ?? [];

            if (is_array($editorActions)) {
                /** @var mixed $editorAction */
                foreach ($editorActions as $editorAction) {
                    if (is_string($editorAction) && str_contains($permission, $editorAction)) {
                        return 'editor';
                    }
                }
            }

            /** @var mixed $adminActions */
            $adminActions = $rules['admin_actions'] ?? [];

            if (is_array($adminActions)) {
                /** @var mixed $adminAction */
                foreach ($adminActions as $adminAction) {
                    if (is_string($adminAction) && str_contains($permission, $adminAction)) {
                        return 'admin';
                    }
                }
            }
        }

        return 'member'; // Default fallback
    }

    private function mapRoleToRelation(string $role): string
    {
        /** @var mixed $mappings */
        $mappings = config('spatie-compatibility.role_mappings', []);

        if (is_array($mappings) && isset($mappings[$role]) && is_string($mappings[$role])) {
            return $mappings[$role];
        }

        return $role;
    }

    /**
     * @param Collection<int, stdClass> $batch
     */
    private function processBatchUserPermissions(Collection $batch): void
    {
        if ($this->dryRun) {
            foreach ($batch as $userPermission) {
                /** @var string $permissionName */
                $permissionName = $userPermission->permission_name;

                /** @var int|string $userIdValue */
                $userIdValue = $userPermission->user_id;
                $userId = (string) $userIdValue;

                if (! $this->quietMode) {
                    $this->line(sprintf("  - Would sync permission '%s' for user %s", $permissionName, $userId));
                }
            }

            $this->added += $batch->count();

            return;
        }

        $tuples = [];

        foreach ($batch as $userPermission) {
            /** @var string $permissionName */
            $permissionName = $userPermission->permission_name;

            /** @var int|string $userId */
            $userId = $userPermission->user_id;

            $relation = $this->mapPermissionToRelation($permissionName);
            $object = $this->mapPermissionToObject($permissionName);

            // Check if permission already exists
            try {
                $exists = OpenFga::check(
                    user: 'user:' . $userId,
                    relation: $relation,
                    object: $object,
                );

                if (! $exists) {
                    $tuples[] = [
                        'user' => 'user:' . $userId,
                        'relation' => $relation,
                        'object' => $object,
                    ];
                }
            } catch (Exception) {
                // If check fails, assume permission doesn't exist
                $tuples[] = [
                    'user' => 'user:' . $userId,
                    'relation' => $relation,
                    'object' => $object,
                ];
            }
        }

        if ([] !== $tuples) {
            try {
                OpenFga::writeBatch(writes: $tuples);
                $this->added += count($tuples);

                if (! $this->quietMode) {
                    $this->info(sprintf('  ✅ Added %d new permissions', count($tuples)));
                }
            } catch (Exception $exception) {
                $this->errors += count($tuples);
                $this->warn('  ⚠️  Failed to write batch: ' . $exception->getMessage());
                Log::warning('Failed to sync permissions batch', ['exception' => $exception]);
            }
        }
    }

    /**
     * @param Collection<int, stdClass> $batch
     */
    private function processBatchUserRoles(Collection $batch): void
    {
        if ($this->dryRun) {
            foreach ($batch as $userRole) {
                /** @var string $roleName */
                $roleName = $userRole->role_name;

                /** @var int|string $userIdValue */
                $userIdValue = $userRole->user_id;
                $userId = (string) $userIdValue;

                if (! $this->quietMode) {
                    $this->line(sprintf("  - Would sync role '%s' for user %s", $roleName, $userId));
                }
            }

            $this->added += $batch->count();

            return;
        }

        $tuples = [];

        foreach ($batch as $userRole) {
            /** @var string $roleName */
            $roleName = $userRole->role_name;

            /** @var int|string $userId */
            $userId = $userRole->user_id;

            $relation = $this->mapRoleToRelation($roleName);

            /** @var mixed $context */
            $context = config('spatie-compatibility.default_context', 'organization:main');
            $object = is_string($context) ? $context : 'organization:main';

            // Check if role already exists
            try {
                $exists = OpenFga::check(
                    user: 'user:' . $userId,
                    relation: $relation,
                    object: $object,
                );

                if (! $exists) {
                    $tuples[] = [
                        'user' => 'user:' . $userId,
                        'relation' => $relation,
                        'object' => $object,
                    ];
                }
            } catch (Exception) {
                // If check fails, assume role doesn't exist
                $tuples[] = [
                    'user' => 'user:' . $userId,
                    'relation' => $relation,
                    'object' => $object,
                ];
            }
        }

        if ([] !== $tuples) {
            try {
                OpenFga::writeBatch(writes: $tuples);
                $this->added += count($tuples);

                if (! $this->quietMode) {
                    $this->info(sprintf('  ✅ Added %d new role assignments', count($tuples)));
                }
            } catch (Exception $exception) {
                $this->errors += count($tuples);
                $this->warn('  ⚠️  Failed to write batch: ' . $exception->getMessage());
                Log::warning('Failed to sync roles batch', ['exception' => $exception]);
            }
        }
    }

    private function removeOrphanedPermissions(): void
    {
        if (! $this->quietMode) {
            $this->info('🧹 Removing orphaned permissions from OpenFGA...');
        }

        // This would require listing all OpenFGA tuples and comparing with Spatie
        // For now, log a warning that this needs to be implemented
        $this->warn('  ⚠️  Full sync removal not yet implemented. Manual cleanup may be required.');
    }

    private function storeRolePermissionMapping(string $roleName, string $permissionName): void
    {
        // This could be used to update the authorization model or store mappings
        // For now, it's a placeholder for future enhancement
        Log::debug('Role permission mapping', [
            'role' => $roleName,
            'permission' => $permissionName,
            'relation' => $this->mapPermissionToRelation($permissionName),
            'object' => $this->mapPermissionToObject($permissionName),
        ]);
    }

    private function syncRolePermission(string $roleName, string $permissionName): void
    {
        if ($this->dryRun) {
            if (! $this->quietMode) {
                $this->line(sprintf("    - Would sync permission '%s' for role '%s'", $permissionName, $roleName));
            }

            return;
        }

        // In OpenFGA, role permissions are typically modeled in the authorization model
        // This is a placeholder for storing the mapping
        $this->storeRolePermissionMapping($roleName, $permissionName);
    }

    private function syncRoles(): void
    {
        if (! $this->quietMode) {
            $this->info('📋 Synchronizing roles...');
        }

        $roles = DB::table('roles')->get();

        foreach ($roles as $role) {
            $roleName = isset($role->name) && is_scalar($role->name) ? (string) $role->name : '';

            if (! $this->quietMode) {
                $this->line('  - Syncing role: ' . $roleName);
            }

            // Get permissions for this role
            /** @var Collection<int, string> $permissions */
            $permissions = DB::table('role_has_permissions')
                ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
                ->where('role_has_permissions.role_id', '=', $role->id)
                ->pluck('permissions.name');

            foreach ($permissions as $permission) {
                $this->syncRolePermission($roleName, $permission);
            }
        }
    }

    private function syncUserPermissions(): void
    {
        if (! $this->quietMode) {
            $this->info('🔑 Synchronizing direct user permissions...');
        }

        /** @var mixed $userModel */
        $userModel = config('auth.providers.users.model');
        $userPermissions = DB::table('model_has_permissions')
            ->where('model_type', $userModel)
            ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
            ->select(['model_has_permissions.model_id as user_id', 'permissions.name as permission_name'])
            ->get();

        /** @var array<int, stdClass> $batch */
        $batch = [];

        foreach ($userPermissions as $userPermission) {
            $batch[] = $userPermission;

            if (count($batch) >= $this->batchSize) {
                $this->processBatchUserPermissions(collect($batch));
                $batch = [];
            }
        }

        if ([] !== $batch) {
            $this->processBatchUserPermissions(collect($batch));
        }
    }

    private function syncUserRoles(): void
    {
        if (! $this->quietMode) {
            $this->info('👥 Synchronizing user roles...');
        }

        /** @var mixed $userModel */
        $userModel = config('auth.providers.users.model');
        $userRoles = DB::table('model_has_roles')
            ->where('model_type', $userModel)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->select(['model_has_roles.model_id as user_id', 'roles.name as role_name'])
            ->get();

        /** @var array<int, stdClass> $batch */
        $batch = [];

        foreach ($userRoles as $userRole) {
            $batch[] = $userRole;

            if (count($batch) >= $this->batchSize) {
                $this->processBatchUserRoles(collect($batch));
                $batch = [];
            }
        }

        if ([] !== $batch) {
            $this->processBatchUserRoles(collect($batch));
        }
    }
}
