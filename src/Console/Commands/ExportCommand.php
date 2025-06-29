<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\Export\PermissionExporter;

use function count;
use function is_string;
use function sprintf;

final class ExportCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string|null
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
     */
    public function handle(): int
    {
        /** @var string $file */
        $file = $this->argument('file');

        // Build filters
        /** @var array{user?: non-empty-string, object?: non-empty-string, object_type?: non-empty-string, relation?: non-empty-string} $filters */
        $filters = array_filter([
            'user' => $this->option('user'),
            'object' => $this->option('object'),
            'object_type' => $this->option('object-type'),
            'relation' => $this->option('relation'),
        ], static fn ($value): bool => is_string($value) && '' !== $value);

        // Build options
        /** @var string|null $format */
        $format = $this->option('format');

        $options = [
            'format' => $format,
            'include_metadata' => ! (bool) $this->option('no-metadata'),
            'pretty_print' => ! (bool) $this->option('compact'),
        ];

        // Warn if no filters
        if ([] === $filters) {
            $this->warn('No filters specified. This will export ALL permissions.');

            if (! $this->confirm('Do you want to continue?')) {
                return self::FAILURE;
            }
        }

        $this->info(sprintf('Exporting permissions to: %s', $file));

        if ([] !== $filters) {
            $this->comment('Filters:');

            foreach ($filters as $key => $value) {
                $this->comment(sprintf('  %s: %s', $key, $value));
            }
        }

        try {
            /** @var array{format?: string, include_metadata?: bool, pretty_print?: bool} $exportOptions */
            $exportOptions = [];

            if (null !== $options['format']) {
                $exportOptions['format'] = $options['format'];
            }
            $exportOptions['include_metadata'] = $options['include_metadata'];
            $exportOptions['pretty_print'] = $options['pretty_print'];

            $exporter = app(PermissionExporter::class);

            $count = $exporter->exportToFile($file, $filters, $exportOptions);

            $this->info(sprintf('✅ Successfully exported %d permissions to %s', $count, $file));

            // Show file size
            if (file_exists($file)) {
                $filesize = filesize($file);

                if (false !== $filesize) {
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
        $size = (float) $bytes;

        while (1024 <= $size && $i < count($units) - 1) {
            $size /= 1024.0;
            ++$i;
        }

        /** @var int<0, 3> $i */
        return number_format($size, 2) . ' ' . $units[$i];
    }
}
