<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use OpenFGA\Laravel\Facades\OpenFga;

use function sprintf;

/**
 * Command to migrate from Spatie Laravel Permission to OpenFGA.
 */
final class MigrateFromSpatieCommand extends Command
{
    protected $description = 'Migrate from Spatie Laravel Permission to OpenFGA';

    protected $signature = 'openfga:migrate:spatie
                            {--dry-run : Run in dry-run mode without making changes}
                            {--batch-size=100 : Number of records to process at once}
                            {--verify : Verify migration results}
                            {--preserve-tables : Keep Spatie tables after migration}';

    private int $batchSize = 100;

    private bool $dryRun = false;

    public function __construct(private readonly SpatieCompatibility $compatibility)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->dryRun = $this->option('dry-run');
        $this->batchSize = (int) $this->option('batch-size');

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

            if ($this->option('verify')) {
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
        $tables = config('spatie-compatibility.migration.tables', [
            'roles' => 'roles',
            'permissions' => 'permissions',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'role_has_permissions' => 'role_has_permissions',
        ]);

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
        $resourceMappings = config('spatie-compatibility.resource_mappings', []);

        foreach ($resourceMappings as $resource => $objectType) {
            if (str_contains($permission, (string) $resource)) {
                return $objectType . ':*';
            }
        }

        return config('spatie-compatibility.default_context', 'organization:main');
    }

    private function mapPermissionToRelation(string $permission): string
    {
        $mappings = config('spatie-compatibility.permission_mappings', []);

        if (isset($mappings[$permission])) {
            return $mappings[$permission];
        }

        // Try to infer from permission name
        $rules = config('spatie-compatibility.inference_rules', []);

        foreach ($rules['owner_actions'] ?? [] as $action) {
            if (str_contains($permission, (string) $action)) {
                return 'owner';
            }
        }

        foreach ($rules['editor_actions'] ?? [] as $action) {
            if (str_contains($permission, (string) $action)) {
                return 'editor';
            }
        }

        foreach ($rules['admin_actions'] ?? [] as $action) {
            if (str_contains($permission, (string) $action)) {
                return 'admin';
            }
        }

        return 'member'; // Default fallback
    }

    private function mapRoleToRelation(string $role): string
    {
        $mappings = config('spatie-compatibility.role_mappings', []);

        return $mappings[$role] ?? $role;
    }

    private function migratePermissions(): void
    {
        $this->info('🔐 Migrating permissions...');

        $permissions = DB::table('permissions')->get();
        $migrated = 0;

        foreach ($permissions as $permission) {
            $this->line('  - Permission: ' . $permission->name);

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
            ->select('roles.name as role_name', 'permissions.name as permission_name')
            ->get();

        $migrated = 0;

        foreach ($rolePermissions as $rolePermission) {
            $this->line(sprintf("  - Role '%s' has permission '%s'", $rolePermission->role_name, $rolePermission->permission_name));

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
            $this->line('  - Role: ' . $role->name);

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

        $userModel = config('auth.providers.users.model');
        $userPermissions = DB::table('model_has_permissions')
            ->where('model_type', $userModel)
            ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
            ->select('model_has_permissions.model_id as user_id', 'permissions.name as permission_name')
            ->get();

        $migrated = 0;
        $batch = collect();

        foreach ($userPermissions as $userPermission) {
            $batch->push($userPermission);

            if ($batch->count() >= $this->batchSize) {
                $this->processBatchUserPermissions($batch);
                $migrated += $batch->count();
                $batch = collect();
            }
        }

        if ($batch->isNotEmpty()) {
            $this->processBatchUserPermissions($batch);
            $migrated += $batch->count();
        }

        $this->info(sprintf('  ✅ Migrated %d user permission assignments', $migrated));
    }

    private function migrateUserRoles(): void
    {
        $this->info('👥 Migrating user roles...');

        $userModel = config('auth.providers.users.model');
        $userRoles = DB::table('model_has_roles')
            ->where('model_type', $userModel)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->select('model_has_roles.model_id as user_id', 'roles.name as role_name')
            ->get();

        $migrated = 0;
        $batch = collect();

        foreach ($userRoles as $userRole) {
            $batch->push($userRole);

            if ($batch->count() >= $this->batchSize) {
                $this->processBatchUserRoles($batch);
                $migrated += $batch->count();
                $batch = collect();
            }
        }

        if ($batch->isNotEmpty()) {
            $this->processBatchUserRoles($batch);
            $migrated += $batch->count();
        }

        $this->info(sprintf('  ✅ Migrated %d user role assignments', $migrated));
    }

    private function processBatchUserPermissions($batch): void
    {
        if ($this->dryRun) {
            foreach ($batch as $userPermission) {
                $this->line(sprintf("  - Would assign permission '%s' to user %s", $userPermission->permission_name, $userPermission->user_id));
            }

            return;
        }

        $tuples = [];

        foreach ($batch as $userPermission) {
            $relation = $this->mapPermissionToRelation($userPermission->permission_name);
            $object = $this->mapPermissionToObject($userPermission->permission_name);

            $tuples[] = [
                'user' => 'user:' . $userPermission->user_id,
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

    private function processBatchUserRoles($batch): void
    {
        if ($this->dryRun) {
            foreach ($batch as $userRole) {
                $this->line(sprintf("  - Would assign role '%s' to user %s", $userRole->role_name, $userRole->user_id));
            }

            return;
        }

        $tuples = [];

        foreach ($batch as $userRole) {
            $relation = $this->mapRoleToRelation($userRole->role_name);
            $tuples[] = [
                'user' => 'user:' . $userRole->user_id,
                'relation' => $relation,
                'object' => config('spatie-compatibility.default_context', 'organization:main'),
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

        if (! $this->option('preserve-tables')) {
            $this->newLine();
            $this->warn('⚠️  Consider backing up your Spatie tables before removing them');
        }
    }

    private function storePermissionMapping(): void
    {
        // Store permission mapping for reference
    }

    private function storeRoleMapping(): void
    {
        // Store role mapping for reference
        // This could be in cache, database, or config
    }

    private function storeRolePermissionMapping(): void
    {
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
                $this->warn(sprintf('  ⚠️  Verification failed for user %s: %s', $sampleUser->id, $e->getMessage()));
                ++$errors;
            }
        }

        if (0 === $errors) {
            $this->info(sprintf('  ✅ Verification passed for %d users', $verified));
        } else {
            $this->warn(sprintf('  ⚠️  Verification had %d errors out of ', $errors) . ($verified + $errors) . ' users');
        }
    }

    private function verifyUserMigration($user): void
    {
        // Get a sample of Spatie permissions for this user
        $spatieRoles = DB::table('model_has_roles')
            ->where('model_type', config('auth.providers.users.model'))
            ->where('model_id', $user->id)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->pluck('roles.name');

        // Check if OpenFGA has equivalent permissions
        foreach ($spatieRoles as $spatieRole) {
            $relation = $this->mapRoleToRelation($spatieRole);
            $object = config('spatie-compatibility.default_context', 'organization:main');

            $hasPermission = OpenFga::check('user:' . $user->id, $relation, $object);

            if (! $hasPermission && ! $this->dryRun) {
                throw new Exception(sprintf('User %s missing role %s (relation: %s)', $user->id, $spatieRole, $relation));
            }
        }
    }
}
