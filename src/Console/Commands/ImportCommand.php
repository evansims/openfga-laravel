<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\Import\PermissionImporter;

use function is_string;
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
     */
    public function handle(): int
    {
        /** @var string $file */
        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->error(sprintf('File not found: %s', $file));

            return self::FAILURE;
        }

        /** @var array{format?: string, batch_size: int, skip_errors: bool, dry_run: bool, clear_existing: bool, validate: bool} */
        $options = [];

        $format = $this->option('format');

        if (is_string($format)) {
            $options['format'] = $format;
        }

        $batchSize = $this->option('batch-size');
        $options['batch_size'] = is_numeric($batchSize) ? (int) $batchSize : 100;
        $options['skip_errors'] = (bool) $this->option('skip-errors');
        $options['dry_run'] = (bool) $this->option('dry-run');
        $options['clear_existing'] = (bool) $this->option('clear-existing');
        $options['validate'] = ! (bool) $this->option('no-validate');

        if ($options['dry_run']) {
            $this->warn('Running in dry-run mode. No changes will be made.');
        }

        if ($options['clear_existing'] && ! $options['dry_run']) {
            if (! $this->confirm('This will delete ALL existing permissions. Are you sure?')) {
                return self::FAILURE;
            }
            $this->warn('Clearing existing permissions is not yet implemented.');
        }

        $this->info(sprintf('Importing permissions from: %s', $file));

        try {
            $importer = app(PermissionImporter::class);

            $stats = $importer->importFromFile($file, $options);

            $this->displayResults($stats);

            return 0 < $stats['errors'] && ! $options['skip_errors']
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
     * @param array<string, mixed> $stats
     */
    private function displayResults(array $stats): void
    {
        $this->newLine();
        $this->info('Import completed!');

        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed'] ?? 0],
                ['Imported', $stats['imported'] ?? 0],
                ['Skipped', $stats['skipped'] ?? 0],
                ['Errors', $stats['errors'] ?? 0],
            ],
        );

        $errors = isset($stats['errors']) && is_numeric($stats['errors']) ? (int) $stats['errors'] : 0;

        if (0 < $errors) {
            $this->warn(sprintf('There were %d errors during import.', $errors));

            if (true !== $this->option('skip-errors')) {
                $this->error('Import was halted due to errors.');
            }
        }

        if (true === $this->option('dry-run')) {
            $this->comment('This was a dry run. No permissions were actually imported.');
        }
    }
}
