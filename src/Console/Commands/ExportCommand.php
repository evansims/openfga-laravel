<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\Export\PermissionExporter;

use function count;
use function sprintf;

final class ExportCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export permissions to a file';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:export
                            {file : The file to export to}
                            {--format= : File format (json, csv, yaml)}
                            {--user= : Export permissions for specific user}
                            {--object= : Export permissions for specific object}
                            {--object-type= : Export permissions for specific object type}
                            {--relation= : Filter by relation type}
                            {--no-metadata : Exclude metadata from export}
                            {--compact : Compact output (no pretty printing)}
                            {--connection= : The OpenFGA connection to use}';

    /**
     * Execute the console command.
     *
     * @param PermissionExporter $exporter
     */
    public function handle(PermissionExporter $exporter): int
    {
        $file = $this->argument('file');

        // Build filters
        $filters = array_filter([
            'user' => $this->option('user'),
            'object' => $this->option('object'),
            'object_type' => $this->option('object-type'),
            'relation' => $this->option('relation'),
        ]);

        // Build options
        $options = [
            'format' => $this->option('format'),
            'include_metadata' => ! $this->option('no-metadata'),
            'pretty_print' => ! $this->option('compact'),
        ];

        // Warn if no filters
        if ([] === $filters) {
            $this->warn('No filters specified. This will export ALL permissions.');

            if (! $this->confirm('Do you want to continue?')) {
                return self::FAILURE;
            }
        }

        $this->info('Exporting permissions to: ' . $file);

        if ([] !== $filters) {
            $this->comment('Filters:');

            foreach ($filters as $key => $value) {
                $this->comment(sprintf('  %s: %s', $key, $value));
            }
        }

        try {
            $count = $exporter->exportToFile($file, $filters, $options);

            $this->info(sprintf('✅ Successfully exported %d permissions to %s', $count, $file));

            // Show file size
            if (file_exists($file)) {
                $filesize = filesize($file);
                if ($filesize !== false) {
                    $size = $this->formatFileSize($filesize);
                    $this->comment('File size: ' . $size);
                }
            }

            return self::SUCCESS;
        } catch (Exception $exception) {
            $this->error('Export failed: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Format file size for display.
     *
     * @param int $bytes
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while (1024 <= $bytes && $i < count($units) - 1) {
            $bytes /= 1024;
            ++$i;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
