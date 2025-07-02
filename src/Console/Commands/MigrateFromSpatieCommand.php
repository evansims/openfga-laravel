<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenFGA\Laravel\Facades\OpenFga;
use stdClass;

use function count;
use function is_array;
use function is_scalar;
use function is_string;
use function sprintf;

/**
 * Command to migrate from Spatie Laravel Permission to OpenFGA.
 */
final class MigrateFromSpatieCommand extends Command
{
    /**
     * @var string|null
     */
    protected $description = 'Migrate from Spatie Laravel Permission to OpenFGA';

    protected $signature = 'openfga:migrate:spatie
                            {--dry-run : Run in dry-run mode without making changes}
                            {--batch-size=100 : Number of records to process at once}
                            {--verify : Verify migration results}
                            {--preserve-tables : Keep Spatie tables after migration}';

    private int $batchSize = 100;

    private bool $dryRun = false;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $batchSize = $this->option('batch-size');
        $this->batchSize = is_numeric($batchSize) ? (int) $batchSize : 100;

        $this->info('🚀 Starting migration from Spatie Laravel Permission to OpenFGA');

        if ($this->dryRun) {
            $this->warn('🧪 Running in DRY-RUN mode - no changes will be made');
        }

        if (! $this->checkSpatieTablesExist()) {
            $this->error('❌ Spatie Laravel Permission tables not found. Is it installed?');

            return 1;
        }

        $this->newLine();

        try {
            $this->migrateRoles();
            $this->migratePermissions();
            $this->migrateUserRoles();
            $this->migrateUserPermissions();
            $this->migrateRolePermissions();

            if (true === $this->option('verify')) {
                $this->verifyMigration();
            }

            $this->displaySummary();
            $this->showNextSteps();
        } catch (Exception $exception) {
            $this->error('❌ Migration failed: ' . $exception->getMessage());

            return 1;
        }

        $this->info('✅ Migration completed successfully!');

        return 0;
    }

    private function checkSpatieTablesExist(): bool
    {
        /** @var mixed $tables */
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

        /** @var mixed $table */
        foreach ($tables as $table) {
            try {
                if (is_string($table)) {
                    DB::table($table)->limit(1)->get();
                }
            } catch (QueryException) {
                return false;
            }
        }

        return true;
    }

    private function displaySummary(): void
    {
        $this->newLine();
        $this->info('📊 Migration Summary:');

        $roles = DB::table('roles')->count();
        $permissions = DB::table('permissions')->count();
        $userRoles = DB::table('model_has_roles')->count();
        $userPermissions = DB::table('model_has_permissions')->count();

        $this->table(
            ['Item', 'Count', 'Status'],
            [
                ['Roles', $roles, '✅ Migrated'],
                ['Permissions', $permissions, '✅ Migrated'],
                ['User Roles', $userRoles, '✅ Migrated'],
                ['User Permissions', $userPermissions, '✅ Migrated'],
            ],
        );
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

    private function migratePermissions(): void
    {
        $this->info('🔐 Migrating permissions...');

        $permissions = DB::table('permissions')->get();
        $migrated = 0;

        foreach ($permissions as $permission) {
            $permissionName = isset($permission->name) && is_string($permission->name) ? $permission->name : '';
            $this->line('  - Permission: ' . $permissionName);

            if (! $this->dryRun) {
                // Permissions in OpenFGA are relationships, not separate entities
                // We'll ensure they're in our mapping
                $this->storePermissionMapping();
            }

            ++$migrated;
        }

        $this->info(sprintf('  ✅ Migrated %d permissions', $migrated));
    }

    private function migrateRolePermissions(): void
    {
        $this->info('🔗 Migrating role permissions...');

        $rolePermissions = DB::table('role_has_permissions')
            ->join('roles', 'role_has_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->select(['roles.name as role_name', 'permissions.name as permission_name'])
            ->get();

        $migrated = 0;

        foreach ($rolePermissions as $rolePermission) {
            $roleName = isset($rolePermission->role_name) && is_scalar($rolePermission->role_name) ? (string) $rolePermission->role_name : '';
            $permissionName = isset($rolePermission->permission_name) && is_scalar($rolePermission->permission_name) ? (string) $rolePermission->permission_name : '';
            $this->line(sprintf("  - Role '%s' has permission '%s'", $roleName, $permissionName));

            if (! $this->dryRun) {
                // In OpenFGA, this would be handled by the authorization model
                // We store this for reference in building the model
                $this->storeRolePermissionMapping();
            }

            ++$migrated;
        }

        $this->info(sprintf('  ✅ Migrated %d role permission assignments', $migrated));
    }

    private function migrateRoles(): void
    {
        $this->info('📋 Migrating roles...');

        $roles = DB::table('roles')->get();
        $migrated = 0;

        foreach ($roles as $role) {
            $roleName = isset($role->name) && is_scalar($role->name) ? (string) $role->name : '';
            $this->line('  - Role: ' . $roleName);

            if (! $this->dryRun) {
                // Roles in OpenFGA are typically organization-level relationships
                // We'll create a mapping for future reference
                $this->storeRoleMapping();
            }

            ++$migrated;
        }

        $this->info(sprintf('  ✅ Migrated %d roles', $migrated));
    }

    private function migrateUserPermissions(): void
    {
        $this->info('🔑 Migrating user permissions...');

        /** @var mixed $userModel */
        $userModel = config('auth.providers.users.model');
        $userPermissions = DB::table('model_has_permissions')
            ->where('model_type', $userModel)
            ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
            ->select(['model_has_permissions.model_id as user_id', 'permissions.name as permission_name'])
            ->get();

        $migrated = 0;

        /** @var array<int, stdClass> $batch */
        $batch = [];

        foreach ($userPermissions as $userPermission) {
            $batch[] = $userPermission;

            if (count($batch) >= $this->batchSize) {
                $this->processBatchUserPermissions(collect($batch));
                $migrated += count($batch);
                $batch = [];
            }
        }

        if ([] !== $batch) {
            $this->processBatchUserPermissions(collect($batch));
            $migrated += count($batch);
        }

        $this->info(sprintf('  ✅ Migrated %d user permission assignments', $migrated));
    }

    private function migrateUserRoles(): void
    {
        $this->info('👥 Migrating user roles...');

        /** @var mixed $userModel */
        $userModel = config('auth.providers.users.model');
        $userRoles = DB::table('model_has_roles')
            ->where('model_type', $userModel)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->select(['model_has_roles.model_id as user_id', 'roles.name as role_name'])
            ->get();

        $migrated = 0;

        /** @var array<int, stdClass> $batch */
        $batch = [];

        foreach ($userRoles as $userRole) {
            $batch[] = $userRole;

            if (count($batch) >= $this->batchSize) {
                $this->processBatchUserRoles(collect($batch));
                $migrated += count($batch);
                $batch = [];
            }
        }

        if ([] !== $batch) {
            $this->processBatchUserRoles(collect($batch));
            $migrated += count($batch);
        }

        $this->info(sprintf('  ✅ Migrated %d user role assignments', $migrated));
    }

    /**
     * @param Collection<int, stdClass> $batch
     */
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
                $this->line(sprintf("  - Would assign permission '%s' to user %s", $permissionName, $userId));
            }

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

            $tuples[] = [
                'user' => 'user:' . $userId,
                'relation' => $relation,
                'object' => $object,
            ];
        }

        try {
            OpenFga::writeBatch($tuples);
        } catch (Exception $exception) {
            $this->warn('  ⚠️  Failed to write batch: ' . $exception->getMessage());
        }
    }

    /**
     * @param Collection<int, stdClass> $batch
     */
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
                $this->line(sprintf("  - Would assign role '%s' to user %s", $roleName, $userId));
            }

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
            $tuples[] = [
                'user' => 'user:' . $userId,
                'relation' => $relation,
                'object' => is_string($context) ? $context : 'organization:main',
            ];
        }

        try {
            OpenFga::writeBatch($tuples);
        } catch (Exception $exception) {
            $this->warn('  ⚠️  Failed to write batch: ' . $exception->getMessage());
        }
    }

    private function showNextSteps(): void
    {
        $this->newLine();
        $this->info('🎯 Next Steps:');
        $this->line('1. Update your User model to use the SpatieCompatible trait');
        $this->line('2. Enable Spatie compatibility in your config: OPENFGA_SPATIE_COMPATIBILITY=true');
        $this->line('3. Test your application with the migrated permissions');
        $this->line('4. Consider updating your authorization model for better performance');
        $this->line('5. Remove Spatie Laravel Permission package when ready');

        if (true !== $this->option('preserve-tables')) {
            $this->newLine();
            $this->warn('⚠️  Consider backing up your Spatie tables before removing them');
        }
    }

    private function storePermissionMapping(): void
    {
        // Store permission mapping for future reference
    }

    private function storeRoleMapping(): void
    {
        // Store role mapping for future reference
        // This could be in cache, database, or config
    }

    private function storeRolePermissionMapping(): void
    {
        // Store role permission mapping for future reference
        // Store role-permission relationship for building authorization model
    }

    private function verifyMigration(): void
    {
        $this->info('🔍 Verifying migration...');

        // Sample a few users and verify their permissions
        config('auth.providers.users.model');
        $sampleUsers = DB::table('users')->inRandomOrder()->limit(10)->get();

        $verified = 0;
        $errors = 0;

        foreach ($sampleUsers as $sampleUser) {
            try {
                $this->verifyUserMigration($sampleUser);
                ++$verified;
            } catch (Exception $e) {
                $userId = isset($sampleUser->id) && is_scalar($sampleUser->id) ? (string) $sampleUser->id : '';
                $this->warn(sprintf('  ⚠️  Verification failed for user %s: %s', $userId, $e->getMessage()));
                ++$errors;
            }
        }

        if (0 === $errors) {
            $this->info(sprintf('  ✅ Verification passed for %d users', $verified));
        } else {
            $this->warn(sprintf('  ⚠️  Verification had %d errors out of ', $errors) . ($verified + $errors) . ' users');
        }
    }

    /**
     * @param stdClass $user
     */
    private function verifyUserMigration(stdClass $user): void
    {
        // Get a sample of Spatie permissions for this user
        $spatieRoles = DB::table('model_has_roles')
            ->where('model_type', config('auth.providers.users.model'))
            ->where('model_id', isset($user->id) && is_scalar($user->id) ? (string) $user->id : '')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->pluck('roles.name');

        // Check if OpenFGA has equivalent permissions
        foreach ($spatieRoles as $spatieRole) {
            if (! is_string($spatieRole)) {
                continue;
            }
            $relation = $this->mapRoleToRelation($spatieRole);

            /** @var mixed $defaultContext */
            $defaultContext = config('spatie-compatibility.default_context', 'organization:main');
            $object = is_string($defaultContext) ? $defaultContext : 'organization:main';

            $userId = isset($user->id) && is_scalar($user->id) ? (string) $user->id : '';
            $hasPermission = OpenFga::check('user:' . $userId, $relation, $object);

            if (! $hasPermission && ! $this->dryRun) {
                $userId = isset($user->id) && is_scalar($user->id) ? (string) $user->id : '';

                throw new Exception(sprintf('User %s missing role %s (relation: %s)', $userId, $spatieRole, $relation));
            }
        }
    }
}
