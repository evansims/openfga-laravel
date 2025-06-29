<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Console\Commands;

use Exception;
use Mockery;
use OpenFGA\Laravel\Console\Commands\ExportCommand;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Export\PermissionExporter;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('ExportCommand', function (): void {
    beforeEach(function (): void {
        // Create mock manager first
        $mockManager = Mockery::mock(ManagerInterface::class);
        $this->app->instance(ManagerInterface::class, $mockManager);

        // Create a partial mock of the final class
        $this->mockExporter = Mockery::mock(new PermissionExporter($mockManager));
        $this->app->instance(PermissionExporter::class, $this->mockExporter);

        $this->tempFile = sys_get_temp_dir() . '/test_export_' . getmypid() . '_' . time() . '.json';
    });

    afterEach(function (): void {
        if (file_exists($this->tempFile)) {
            unlink(filename: $this->tempFile);
        }
        Mockery::close();
    });

    it('cancels export when no filters and no confirmation', function (): void {
        $this->artisan('openfga:export', [
            'file' => $this->tempFile,
        ])
            ->expectsOutput('No filters specified. This will export ALL permissions.')
            ->expectsConfirmation('Do you want to continue?', 'no')
            ->assertFailed();
    });

    it('exports permissions without filters', function (): void {
        $this->mockExporter->shouldReceive('exportToFile')
            ->once()
            ->with(
                $this->tempFile,
                [],
                [
                    'include_metadata' => true,
                    'pretty_print' => true,
                ],
            )
            ->andReturn(42);

        // Create a small test file for size calculation
        file_put_contents($this->tempFile, json_encode(['test' => 'data']));

        $this->artisan('openfga:export', [
            'file' => $this->tempFile,
        ])
            ->expectsOutput('No filters specified. This will export ALL permissions.')
            ->expectsConfirmation('Do you want to continue?', 'yes')
            ->expectsOutput('Exporting permissions to: ' . $this->tempFile)
            ->expectsOutput('✅ Successfully exported 42 permissions to ' . $this->tempFile)
            ->expectsOutputToContain('File size:')
            ->assertSuccessful();
    });

    it('exports with compact output', function (): void {
        $this->mockExporter->shouldReceive('exportToFile')
            ->once()
            ->with(
                $this->tempFile,
                [],
                [
                    'include_metadata' => true,
                    'pretty_print' => false,
                ],
            )
            ->andReturn(25);

        $this->artisan('openfga:export', [
            'file' => $this->tempFile,
            '--compact' => true,
        ])
            ->expectsConfirmation('Do you want to continue?', 'yes')
            ->assertSuccessful();
    });

    it('exports with format option', function (): void {
        $this->mockExporter->shouldReceive('exportToFile')
            ->once()
            ->with(
                $this->tempFile,
                [],
                [
                    'format' => 'csv',
                    'include_metadata' => true,
                    'pretty_print' => true,
                ],
            )
            ->andReturn(20);

        $this->artisan('openfga:export', [
            'file' => $this->tempFile,
            '--format' => 'csv',
        ])
            ->expectsConfirmation('Do you want to continue?', 'yes')
            ->assertSuccessful();
    });

    it('exports with multiple filters', function (): void {
        $this->mockExporter->shouldReceive('exportToFile')
            ->once()
            ->with(
                $this->tempFile,
                [
                    'user' => 'user:123',
                    'object_type' => 'document',
                    'relation' => 'viewer',
                ],
                [
                    'include_metadata' => true,
                    'pretty_print' => true,
                ],
            )
            ->andReturn(5);

        $this->artisan('openfga:export', [
            'file' => $this->tempFile,
            '--user' => 'user:123',
            '--object-type' => 'document',
            '--relation' => 'viewer',
        ])
            ->expectsOutput('Exporting permissions to: ' . $this->tempFile)
            ->expectsOutput('Filters:')
            ->expectsOutput('  user: user:123')
            ->expectsOutput('  object_type: document')
            ->expectsOutput('  relation: viewer')
            ->expectsOutput('✅ Successfully exported 5 permissions to ' . $this->tempFile)
            ->assertSuccessful();
    });

    it('exports with user filter', function (): void {
        $this->mockExporter->shouldReceive('exportToFile')
            ->once()
            ->with(
                $this->tempFile,
                ['user' => 'user:123'],
                [
                    'include_metadata' => true,
                    'pretty_print' => true,
                ],
            )
            ->andReturn(10);

        $this->artisan('openfga:export', [
            'file' => $this->tempFile,
            '--user' => 'user:123',
        ])
            ->expectsOutput('Exporting permissions to: ' . $this->tempFile)
            ->expectsOutput('Filters:')
            ->expectsOutput('  user: user:123')
            ->expectsOutput('✅ Successfully exported 10 permissions to ' . $this->tempFile)
            ->assertSuccessful();
    });

    it('exports without metadata', function (): void {
        $this->mockExporter->shouldReceive('exportToFile')
            ->once()
            ->with(
                $this->tempFile,
                [],
                [
                    'include_metadata' => false,
                    'pretty_print' => true,
                ],
            )
            ->andReturn(15);

        $this->artisan('openfga:export', [
            'file' => $this->tempFile,
            '--no-metadata' => true,
        ])
            ->expectsConfirmation('Do you want to continue?', 'yes')
            ->assertSuccessful();
    });

    it('filters empty option values', function (): void {
        $this->mockExporter->shouldReceive('exportToFile')
            ->once()
            ->with(
                $this->tempFile,
                [], // Empty string options should be filtered out
                Mockery::any(),
            )
            ->andReturn(10);

        $this->artisan('openfga:export', [
            'file' => $this->tempFile,
            '--user' => '',
            '--object' => '',
        ])
            ->expectsConfirmation('Do you want to continue?', 'yes')
            ->assertSuccessful();
    });

    it('formats file sizes correctly', function (): void {
        // Create files of different sizes to test formatting
        $testSizes = [
            512 => '512.00 B',
            1024 => '1.00 KB',
            1048576 => '1.00 MB',
            1073741824 => '1.00 GB',
        ];

        foreach ($testSizes as $size => $expected) {
            // Setup expectations for each iteration
            $this->mockExporter->shouldReceive('exportToFile')
                ->once()
                ->andReturn(100);

            $tempFile = sys_get_temp_dir() . '/size_test_' . $size . '.json';

            // Create a file with specific size
            $handle = fopen($tempFile, 'w');
            fseek($handle, $size - 1);
            fwrite($handle, "\0");
            fclose($handle);

            $this->artisan('openfga:export', [
                'file' => $tempFile,
            ])
                ->expectsConfirmation('Do you want to continue?', 'yes')
                ->expectsOutputToContain('File size: ' . $expected)
                ->assertSuccessful();

            unlink(filename: $tempFile);
        }
    });

    it('handles export errors', function (): void {
        $this->mockExporter->shouldReceive('exportToFile')
            ->once()
            ->andThrow(new Exception('Export failed: Permission denied'));

        $this->artisan('openfga:export', [
            'file' => $this->tempFile,
        ])
            ->expectsConfirmation('Do you want to continue?', 'yes')
            ->expectsOutput('Export failed: Export failed: Permission denied')
            ->assertFailed();
    });

    it('has correct signature', function (): void {
        $command = new ExportCommand;
        $command->setLaravel($this->app);

        expect($command->getName())->toBe('openfga:export');
        expect($command->getDescription())->toContain('Export permissions to a file');

        $definition = $command->getDefinition();

        // Check arguments
        expect($definition->hasArgument('file'))->toBeTrue();
        expect($definition->getArgument('file')->isRequired())->toBeTrue();

        // Check options
        expect($definition->hasOption('format'))->toBeTrue();
        expect($definition->hasOption('user'))->toBeTrue();
        expect($definition->hasOption('object'))->toBeTrue();
        expect($definition->hasOption('object-type'))->toBeTrue();
        expect($definition->hasOption('relation'))->toBeTrue();
        expect($definition->hasOption('no-metadata'))->toBeTrue();
        expect($definition->hasOption('compact'))->toBeTrue();
        expect($definition->hasOption('connection'))->toBeTrue();
    });
});
