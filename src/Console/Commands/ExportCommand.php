<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\Export\PermissionExporter;

class ExportCommand extends Command
{
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
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export permissions to a file';

    /**
     * Execute the console command.
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
        if (empty($filters)) {
            $this->warn('No filters specified. This will export ALL permissions.');
            if (! $this->confirm('Do you want to continue?')) {
                return self::FAILURE;
            }
        }

        $this->info("Exporting permissions to: {$file}");

        if (! empty($filters)) {
            $this->comment('Filters:');
            foreach ($filters as $key => $value) {
                $this->comment("  {$key}: {$value}");
            }
        }

        try {
            $count = $exporter->exportToFile($file, $filters, $options);

            $this->info("✅ Successfully exported {$count} permissions to {$file}");

            // Show file size
            $size = $this->formatFileSize(filesize($file));
            $this->comment("File size: {$size}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Export failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Format file size for display
     */
    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}