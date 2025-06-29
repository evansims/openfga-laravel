<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Console\Commands;

use Exception;
use Mockery;
use OpenFGA\Laravel\Console\Commands\ImportCommand;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Import\PermissionImporter;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('ImportCommand', function (): void {
    beforeEach(function (): void {
        // Create a mock of the manager interface
        $mockManager = Mockery::mock(ManagerInterface::class);
        $this->app->instance(ManagerInterface::class, $mockManager);

        // Create a partial mock of the final class
        $this->mockImporter = Mockery::mock(new PermissionImporter($mockManager));
        $this->app->instance(PermissionImporter::class, $this->mockImporter);

        $this->tempFile = sys_get_temp_dir() . '/test_import_' . getmypid() . '_' . time() . '.json';
        file_put_contents($this->tempFile, json_encode(['permissions' => []]));
    });

    afterEach(function (): void {
        if (file_exists($this->tempFile)) {
            unlink(filename: $this->tempFile);
        }
        Mockery::close();
    });

    it('continues with errors when skip errors enabled', function (): void {
        $this->mockImporter->shouldReceive('importFromFile')
            ->once()
            ->with(
                $this->tempFile,
                Mockery::on(fn ($options) => true === $options['skip_errors']),
            )
            ->andReturn([
                'processed' => 50,
                'imported' => 40,
                'skipped' => 5,
                'errors' => 5,
            ]);

        $this->artisan('openfga:import', [
            'file' => $this->tempFile,
            '--skip-errors' => true,
        ])
            ->expectsOutput('Import completed!')
            ->expectsOutput('There were 5 errors during import.')
            ->doesntExpectOutput('Import was halted due to errors.')
            ->assertSuccessful();
    });

    it('fails when file does not exist', function (): void {
        $this->artisan('openfga:import', [
            'file' => '/non/existent/file.json',
        ])
            ->expectsOutput('File not found: /non/existent/file.json')
            ->assertFailed();
    });

    it('handles errors without skip errors', function (): void {
        $this->mockImporter->shouldReceive('importFromFile')
            ->once()
            ->andReturn([
                'processed' => 50,
                'imported' => 40,
                'skipped' => 5,
                'errors' => 5,
            ]);

        $this->artisan('openfga:import', [
            'file' => $this->tempFile,
        ])
            ->expectsOutput('Import completed!')
            ->expectsOutput('There were 5 errors during import.')
            ->expectsOutput('Import was halted due to errors.')
            ->assertFailed();
    });

    it('handles import exceptions', function (): void {
        $this->mockImporter->shouldReceive('importFromFile')
            ->once()
            ->andThrow(new Exception('Invalid file format'));

        $this->artisan('openfga:import', [
            'file' => $this->tempFile,
        ])
            ->expectsOutput('Import failed: Invalid file format')
            ->assertFailed();
    });

    it('handles non numeric batch size', function (): void {
        $this->mockImporter->shouldReceive('importFromFile')
            ->once()
            ->with(
                $this->tempFile,
                Mockery::on(function ($options) {
                    return 100 === $options['batch_size']; // Should use default
                }),
            )
            ->andReturn([
                'processed' => 50,
                'imported' => 50,
                'skipped' => 0,
                'errors' => 0,
            ]);

        $this->artisan('openfga:import', [
            'file' => $this->tempFile,
            '--batch-size' => 'invalid',
        ])
            ->assertSuccessful();
    });

    it('has correct signature', function (): void {
        $command = new ImportCommand;
        $command->setLaravel($this->app);

        expect($command->getName())->toBe('openfga:import');
        expect($command->getDescription())->toContain('Import permissions from a file');

        $definition = $command->getDefinition();

        // Check arguments
        expect($definition->hasArgument('file'))->toBeTrue();
        expect($definition->getArgument('file')->isRequired())->toBeTrue();

        // Check options
        expect($definition->hasOption('format'))->toBeTrue();
        expect($definition->hasOption('batch-size'))->toBeTrue();
        expect($definition->hasOption('skip-errors'))->toBeTrue();
        expect($definition->hasOption('dry-run'))->toBeTrue();
        expect($definition->hasOption('clear-existing'))->toBeTrue();
        expect($definition->hasOption('no-validate'))->toBeTrue();
        expect($definition->hasOption('connection'))->toBeTrue();
    });

    it('imports permissions successfully', function (): void {
        $this->mockImporter->shouldReceive('importFromFile')
            ->once()
            ->with(
                $this->tempFile,
                [
                    'batch_size' => 100,
                    'skip_errors' => false,
                    'dry_run' => false,
                    'clear_existing' => false,
                    'validate' => true,
                ],
            )
            ->andReturn([
                'processed' => 50,
                'imported' => 48,
                'skipped' => 2,
                'errors' => 0,
            ]);

        $this->artisan('openfga:import', [
            'file' => $this->tempFile,
        ])
            ->expectsOutput('Importing permissions from: ' . $this->tempFile)
            ->expectsOutput('Import completed!')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Processed', 50],
                    ['Imported', 48],
                    ['Skipped', 2],
                    ['Errors', 0],
                ],
            )
            ->assertSuccessful();
    });

    it('imports with custom batch size', function (): void {
        $this->mockImporter->shouldReceive('importFromFile')
            ->once()
            ->with(
                $this->tempFile,
                Mockery::on(fn ($options) => 250 === $options['batch_size']),
            )
            ->andReturn([
                'processed' => 100,
                'imported' => 100,
                'skipped' => 0,
                'errors' => 0,
            ]);

        $this->artisan('openfga:import', [
            'file' => $this->tempFile,
            '--batch-size' => 250,
        ])
            ->assertSuccessful();
    });

    it('imports without validation', function (): void {
        $this->mockImporter->shouldReceive('importFromFile')
            ->once()
            ->with(
                $this->tempFile,
                Mockery::on(fn ($options) => false === $options['validate']),
            )
            ->andReturn([
                'processed' => 30,
                'imported' => 30,
                'skipped' => 0,
                'errors' => 0,
            ]);

        $this->artisan('openfga:import', [
            'file' => $this->tempFile,
            '--no-validate' => true,
        ])
            ->assertSuccessful();
    });

    it('passes format option', function (): void {
        $this->mockImporter->shouldReceive('importFromFile')
            ->once()
            ->with(
                $this->tempFile,
                Mockery::on(fn ($options) => isset($options['format']) && 'csv' === $options['format']),
            )
            ->andReturn([
                'processed' => 100,
                'imported' => 100,
                'skipped' => 0,
                'errors' => 0,
            ]);

        $this->artisan('openfga:import', [
            'file' => $this->tempFile,
            '--format' => 'csv',
        ])
            ->assertSuccessful();
    });

    it('prompts for clear existing confirmation', function (): void {
        $this->artisan('openfga:import', [
            'file' => $this->tempFile,
            '--clear-existing' => true,
        ])
            ->expectsConfirmation('This will delete ALL existing permissions. Are you sure?', 'no')
            ->assertFailed();
    });

    it('runs in dry run mode', function (): void {
        $this->mockImporter->shouldReceive('importFromFile')
            ->once()
            ->with(
                $this->tempFile,
                Mockery::on(fn ($options) => true === $options['dry_run']),
            )
            ->andReturn([
                'processed' => 20,
                'imported' => 0,
                'skipped' => 0,
                'errors' => 0,
            ]);

        $this->artisan('openfga:import', [
            'file' => $this->tempFile,
            '--dry-run' => true,
        ])
            ->expectsOutput('Running in dry-run mode. No changes will be made.')
            ->expectsOutput('Import completed!')
            ->expectsOutput('This was a dry run. No permissions were actually imported.')
            ->assertSuccessful();
    });

    it('warns about clear existing not implemented', function (): void {
        $this->mockImporter->shouldReceive('importFromFile')
            ->once()
            ->andReturn([
                'processed' => 10,
                'imported' => 10,
                'skipped' => 0,
                'errors' => 0,
            ]);

        $this->artisan('openfga:import', [
            'file' => $this->tempFile,
            '--clear-existing' => true,
        ])
            ->expectsConfirmation('This will delete ALL existing permissions. Are you sure?', 'yes')
            ->expectsOutput('Clearing existing permissions is not yet implemented.')
            ->assertSuccessful();
    });
});
