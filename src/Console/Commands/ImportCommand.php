<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\Import\PermissionImporter;

use function sprintf;

final class ImportCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import permissions from a file';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:import
                            {file : The file to import from}
                            {--format= : File format (json, csv, yaml)}
                            {--batch-size=100 : Number of permissions to import per batch}
                            {--skip-errors : Continue importing even if errors occur}
                            {--dry-run : Preview what would be imported without making changes}
                            {--clear-existing : Clear all existing permissions before import}
                            {--no-validate : Skip validation checks}
                            {--connection= : The OpenFGA connection to use}';

    /**
     * Execute the console command.
     *
     * @param PermissionImporter $importer
     */
    public function handle(PermissionImporter $importer): int
    {
        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->error('File not found: ' . $file);

            return self::FAILURE;
        }

        $options = [
            'format' => $this->option('format'),
            'batch_size' => (int) $this->option('batch-size'),
            'skip_errors' => $this->option('skip-errors'),
            'dry_run' => $this->option('dry-run'),
            'clear_existing' => $this->option('clear-existing'),
            'validate' => ! $this->option('no-validate'),
        ];

        if ($this->option('dry-run')) {
            $this->warn('Running in dry-run mode. No changes will be made.');
        }

        if ($this->option('clear-existing') && ! $this->option('dry-run')) {
            if (! $this->confirm('This will delete ALL existing permissions. Are you sure?')) {
                return self::FAILURE;
            }
            $this->warn('Clearing existing permissions is not yet implemented.');
        }

        $this->info('Importing permissions from: ' . $file);

        try {
            $stats = $importer->importFromFile($file, $options);

            $this->displayResults($stats);

            return 0 < $stats['errors'] && ! $this->option('skip-errors')
                ? self::FAILURE
                : self::SUCCESS;
        } catch (Exception $exception) {
            $this->error('Import failed: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Display import results.
     *
     * @param array $stats
     */
    protected function displayResults(array $stats): void
    {
        $this->newLine();
        $this->info('Import completed!');

        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                ['Imported', $stats['imported']],
                ['Skipped', $stats['skipped']],
                ['Errors', $stats['errors']],
            ],
        );

        if (0 < $stats['errors']) {
            $this->warn(sprintf('There were %s errors during import.', $stats['errors']));

            if (! $this->option('skip-errors')) {
                $this->error('Import was halted due to errors.');
            }
        }

        if ($this->option('dry-run')) {
            $this->comment('This was a dry run. No permissions were actually imported.');
        }
    }
}
