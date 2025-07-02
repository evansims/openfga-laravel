<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Console\Commands;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Console\Commands\SyncSpatiePermissionsCommand;
use OpenFGA\Laravel\Facades\OpenFga;
use OpenFGA\Laravel\Testing\FakesOpenFga;
use OpenFGA\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SyncSpatiePermissionsCommandTest extends TestCase
{
    use DatabaseTransactions, FakesOpenFga;

    #[Test]
    public function it_shows_error_when_spatie_tables_do_not_exist(): void
    {
        // Force tables to not exist by mocking DB
        DB::shouldReceive('table')->andThrow(new \Illuminate\Database\QueryException(
            'mysql',
            'select * from `roles` limit 1',
            [],
            new \Exception("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'test.roles' doesn't exist")
        ));

        $this->artisan('openfga:sync-spatie-permissions')
            ->expectsOutput('🔄 Synchronizing Spatie Laravel Permission with OpenFGA')
            ->expectsOutput('❌ Spatie Laravel Permission tables not found. Is it installed?')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_runs_in_dry_run_mode(): void
    {
        // Create test tables
        $this->createSpatieTestTables();
        
        // Add test data
        $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
        $permissionId = DB::table('permissions')->insertGetId(['name' => 'edit posts', 'guard_name' => 'web']);
        DB::table('role_has_permissions')->insert([
            'permission_id' => $permissionId,
            'role_id' => $roleId,
        ]);

        $this->artisan('openfga:sync-spatie-permissions', ['--dry-run' => true])
            ->expectsOutput('🔄 Synchronizing Spatie Laravel Permission with OpenFGA')
            ->expectsOutput('🧪 Running in DRY-RUN mode - no changes will be made')
            ->expectsOutputToContain('📋 Synchronizing roles...')
            ->expectsOutputToContain('📊 Sync Summary:')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_syncs_user_permissions(): void
    {
        // Create test tables
        $this->createSpatieTestTables();
        
        // Create test users table
        DB::statement('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY)');
        $userId = DB::table('users')->insertGetId([]);
        
        // Add test data
        $permissionId = DB::table('permissions')->insertGetId(['name' => 'edit posts', 'guard_name' => 'web']);
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');
        
        DB::table('model_has_permissions')->insert([
            'permission_id' => $permissionId,
            'model_type' => $userModel,
            'model_id' => $userId,
        ]);

        // Use fake OpenFGA
        $fake = $this->fakeOpenFga();

        $this->artisan('openfga:sync-spatie-permissions')
            ->expectsOutputToContain('🔑 Synchronizing direct user permissions...')
            ->expectsOutputToContain('📊 Sync Summary:')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_syncs_user_roles(): void
    {
        // Create test tables
        $this->createSpatieTestTables();
        
        // Create test users table
        DB::statement('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY)');
        $userId = DB::table('users')->insertGetId([]);
        
        // Add test data
        $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');
        
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => $userModel,
            'model_id' => $userId,
        ]);

        // Use fake OpenFGA
        $fake = $this->fakeOpenFga();

        $this->artisan('openfga:sync-spatie-permissions')
            ->expectsOutputToContain('👥 Synchronizing user roles...')
            ->expectsOutputToContain('📊 Sync Summary:')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_batch_processing(): void
    {
        // Create test tables
        $this->createSpatieTestTables();
        
        // Create test users table
        DB::statement('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY)');
        
        // Add multiple users with permissions
        $permissionId = DB::table('permissions')->insertGetId(['name' => 'edit posts', 'guard_name' => 'web']);
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');
        
        for ($i = 1; $i <= 5; $i++) {
            $userId = DB::table('users')->insertGetId([]);
            DB::table('model_has_permissions')->insert([
                'permission_id' => $permissionId,
                'model_type' => $userModel,
                'model_id' => $userId,
            ]);
        }

        // Use fake OpenFGA
        $fake = $this->fakeOpenFga();

        $this->artisan('openfga:sync-spatie-permissions', ['--batch-size' => 3])
            ->expectsOutputToContain('📊 Sync Summary:')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_full_sync_mode(): void
    {
        // Create test tables
        $this->createSpatieTestTables();

        $this->artisan('openfga:sync-spatie-permissions', ['--full' => true])
            ->expectsOutput('🔄 Synchronizing Spatie Laravel Permission with OpenFGA')
            ->expectsOutput('⚠️  Running FULL SYNC - OpenFGA permissions not in Spatie will be removed')
            ->expectsOutputToContain('🧹 Removing orphaned permissions from OpenFGA...')
            ->expectsOutputToContain('⚠️  Full sync removal not yet implemented')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_quiet_mode(): void
    {
        // Create test tables
        $this->createSpatieTestTables();

        $this->artisan('openfga:sync-spatie-permissions', ['--quiet-mode' => true])
            ->expectsOutput('🔄 Synchronizing Spatie Laravel Permission with OpenFGA')
            ->doesntExpectOutputToContain('📋 Synchronizing roles...')
            ->expectsOutputToContain('📊 Sync Summary:')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_sync_errors_gracefully(): void
    {
        // Create test tables
        $this->createSpatieTestTables();
        
        // Add test data
        DB::table('permissions')->insert(['name' => 'edit posts', 'guard_name' => 'web']);
        
        // Fake an error during sync by injecting bad data
        $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
        
        // Add a role permission that will cause issues
        DB::table('role_has_permissions')->insert([
            'permission_id' => 999999, // Non-existent permission
            'role_id' => $roleId,
        ]);

        // Use fake OpenFGA
        $fake = $this->fakeOpenFga();

        // The command should complete successfully even with bad data
        $this->artisan('openfga:sync-spatie-permissions')
            ->expectsOutputToContain('📊 Sync Summary:')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_shows_summary_with_errors(): void
    {
        // Create test tables
        $this->createSpatieTestTables();
        
        // Use fake OpenFGA
        $fake = $this->fakeOpenFga();

        // Run sync - with empty tables, there should be no errors
        $this->artisan('openfga:sync-spatie-permissions')
            ->expectsOutputToContain('📊 Sync Summary:')
            ->assertExitCode(0);
    }

    private function createSpatieTestTables(): void
    {
        // Create minimal Spatie tables for testing
        DB::statement('CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY, name VARCHAR(255), guard_name VARCHAR(255))');
        DB::statement('CREATE TABLE IF NOT EXISTS permissions (id INTEGER PRIMARY KEY, name VARCHAR(255), guard_name VARCHAR(255))');
        DB::statement('CREATE TABLE IF NOT EXISTS model_has_permissions (permission_id INTEGER, model_type VARCHAR(255), model_id INTEGER)');
        DB::statement('CREATE TABLE IF NOT EXISTS model_has_roles (role_id INTEGER, model_type VARCHAR(255), model_id INTEGER)');
        DB::statement('CREATE TABLE IF NOT EXISTS role_has_permissions (permission_id INTEGER, role_id INTEGER)');
    }
}