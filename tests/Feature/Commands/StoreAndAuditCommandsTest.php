<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Feature\Commands;

use OpenFGA\Laravel\Tests\TestCase;

final class StoreAndAuditCommandsTest extends TestCase
{
    public function test_analyze_permissions_command_all_options(): void
    {
        $this->artisan('openfga:analyze', [
            '--show-paths' => true,
            '--find-conflicts' => true,
            '--optimize' => true,
        ])
            ->expectsOutputToContain('Analyzing permission structure...')
            ->expectsOutputToContain('Paths:')
            ->expectsOutputToContain('Conflicts:')
            ->expectsOutputToContain('Optimizations:')
            ->assertSuccessful();
    }

    public function test_analyze_permissions_command_default(): void
    {
        $this->artisan('openfga:analyze')
            ->expectsOutputToContain('Analyzing permission structure...')
            ->expectsOutputToContain('Structure:')
            ->expectsOutputToContain('Usage:')
            ->expectsOutputToContain('Health:')
            ->assertSuccessful();
    }

    public function test_analyze_permissions_command_find_conflicts(): void
    {
        $this->artisan('openfga:analyze', ['--find-conflicts' => true])
            ->expectsOutputToContain('Searching for permission conflicts...')
            ->expectsOutputToContain('Conflicts:')
            ->assertSuccessful();
    }

    public function test_analyze_permissions_command_optimize(): void
    {
        $this->artisan('openfga:analyze', ['--optimize' => true])
            ->expectsOutputToContain('Analyzing for optimization opportunities...')
            ->expectsOutputToContain('Suggestions:')
            ->assertSuccessful();
    }

    public function test_analyze_permissions_command_show_paths(): void
    {
        $this->artisan('openfga:analyze', ['--show-paths' => true])
            ->expectsOutputToContain('Analyzing permission inheritance paths...')
            ->expectsOutputToContain('Inheritance Chains:')
            ->assertSuccessful();
    }

    public function test_audit_permissions_command_for_object(): void
    {
        $this->artisan('openfga:audit', ['--object' => 'document:1'])
            ->expectsOutputToContain('Auditing permissions on object: document:1')
            ->assertSuccessful();
    }

    public function test_audit_permissions_command_for_user(): void
    {
        $this->artisan('openfga:audit', ['--user' => 'user:123'])
            ->expectsOutputToContain('Starting permission audit...')
            ->expectsOutputToContain('Auditing permissions for user: user:123')
            ->expectsOutputToContain('Audit Summary:')
            ->assertSuccessful();
    }

    public function test_audit_permissions_command_general(): void
    {
        $this->artisan('openfga:audit')
            ->expectsOutputToContain('Performing general audit')
            ->expectsOutputToContain('Audit Summary:')
            ->assertSuccessful();
    }

    public function test_audit_permissions_command_with_export_csv(): void
    {
        $this->artisan('openfga:audit', [
            '--user' => 'user:123',
            '--export' => 'csv',
        ])
            ->expectsOutputToContain('Audit results exported to:')
            ->assertSuccessful();

        // Clean up exported file
        $files = glob('openfga_audit_*.csv');

        foreach ($files as $file) {
            unlink($file);
        }
    }

    public function test_audit_permissions_command_with_export_json(): void
    {
        $this->artisan('openfga:audit', [
            '--user' => 'user:123',
            '--export' => 'json',
        ])
            ->expectsOutputToContain('Audit results exported to:')
            ->assertSuccessful();

        // Clean up exported file
        $files = glob('openfga_audit_*.json');

        foreach ($files as $file) {
            unlink($file);
        }
    }

    public function test_store_create_command(): void
    {
        $this->artisan('openfga:store:create', ['name' => 'TestStore'])
            ->expectsOutputToContain('Creating store \'TestStore\'...')
            ->expectsOutputToContain('Store created successfully!')
            ->expectsOutputToContain('Store ID: store_')
            ->expectsOutputToContain('Next steps:')
            ->assertSuccessful();
    }

    public function test_store_create_command_with_invalid_model(): void
    {
        $this->artisan('openfga:store:create', [
            'name' => 'TestStore',
            '--model' => '/nonexistent/model.fga',
        ])
            ->expectsOutputToContain('Failed to create store:')
            ->assertFailed();
    }

    public function test_store_create_command_with_model(): void
    {
        $modelFile = tempnam(sys_get_temp_dir(), 'model');
        file_put_contents($modelFile, "model\n  schema 1.1\n\ntype user");

        $this->artisan('openfga:store:create', [
            'name' => 'TestStore',
            '--model' => $modelFile,
        ])
            ->expectsOutputToContain('Creating initial model from:')
            ->assertSuccessful();

        unlink($modelFile);
    }
}
