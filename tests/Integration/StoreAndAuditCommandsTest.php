<?php

declare(strict_types=1);
use OpenFGA\Laravel\Tests\Support\FeatureTestCase;

uses(FeatureTestCase::class);

describe('Store And Audit Commands', function (): void {
    it('analyze permissions command all options', function (): void {
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
    });

    it('analyze permissions command default', function (): void {
        $this->artisan('openfga:analyze')
            ->expectsOutputToContain('Analyzing permission structure...')
            ->expectsOutputToContain('Structure:')
            ->expectsOutputToContain('Usage:')
            ->expectsOutputToContain('Health:')
            ->assertSuccessful();
    });

    it('analyze permissions command find conflicts', function (): void {
        $this->artisan('openfga:analyze', ['--find-conflicts' => true])
            ->expectsOutputToContain('Searching for permission conflicts...')
            ->expectsOutputToContain('Conflicts:')
            ->assertSuccessful();
    });

    it('analyze permissions command optimize', function (): void {
        $this->artisan('openfga:analyze', ['--optimize' => true])
            ->expectsOutputToContain('Analyzing for optimization opportunities...')
            ->expectsOutputToContain('Suggestions:')
            ->assertSuccessful();
    });

    it('analyze permissions command show paths', function (): void {
        $this->artisan('openfga:analyze', ['--show-paths' => true])
            ->expectsOutputToContain('Analyzing permission inheritance paths...')
            ->expectsOutputToContain('Inheritance Chains:')
            ->assertSuccessful();
    });

    it('audit permissions command for object', function (): void {
        $this->artisan('openfga:audit', ['--object' => 'document:1'])
            ->expectsOutputToContain('Auditing permissions on object: document:1')
            ->assertSuccessful();
    });

    it('audit permissions command for user', function (): void {
        $this->artisan('openfga:audit', ['--user' => 'user:123'])
            ->expectsOutputToContain('Starting permission audit...')
            ->expectsOutputToContain('Auditing permissions for user: user:123')
            ->expectsOutputToContain('Audit Summary:')
            ->assertSuccessful();
    });

    it('audit permissions command general', function (): void {
        $this->artisan('openfga:audit')
            ->expectsOutputToContain('Performing general audit')
            ->expectsOutputToContain('Audit Summary:')
            ->assertSuccessful();
    });

    it('audit permissions command with export csv', function (): void {
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
    });

    it('audit permissions command with export json', function (): void {
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
    });

    it('store create command', function (): void {
        $this->artisan('openfga:store:create', ['name' => 'TestStore'])
            ->expectsOutputToContain('Creating store \'TestStore\'...')
            ->expectsOutputToContain('Store created successfully!')
            ->expectsOutputToContain('Store ID: store_')
            ->expectsOutputToContain('Next steps:')
            ->assertSuccessful();
    });

    it('store create command with invalid model', function (): void {
        $this->artisan('openfga:store:create', [
            'name' => 'TestStore',
            '--model' => '/nonexistent/model.fga',
        ])
            ->expectsOutputToContain('Failed to create store:')
            ->assertFailed();
    });

    it('store create command with model', function (): void {
        $modelFile = tempnam(sys_get_temp_dir(), 'model');
        file_put_contents($modelFile, "model\n  schema 1.1\n\ntype user");

        $this->artisan('openfga:store:create', [
            'name' => 'TestStore',
            '--model' => $modelFile,
        ])
            ->expectsOutputToContain('Creating initial model from:')
            ->assertSuccessful();

        unlink($modelFile);
    });
});
