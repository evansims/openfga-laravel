<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\OpenFgaManager;

class PermissionImporter
{
    protected OpenFgaManager $manager;
    protected array $options = [];
    protected array $stats = [
        'processed' => 0,
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    public function __construct(OpenFgaManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Import permissions from a file
     */
    public function importFromFile(string $filename, array $options = []): array
    {
        $this->options = array_merge([
            'format' => $this->detectFormat($filename),
            'batch_size' => 100,
            'skip_errors' => false,
            'dry_run' => false,
            'clear_existing' => false,
            'validate' => true,
        ], $options);

        $this->resetStats();

        if (! file_exists($filename)) {
            throw new \RuntimeException("Import file not found: {$filename}");
        }

        return match ($this->options['format']) {
            'json' => $this->importJson($filename),
            'csv' => $this->importCsv($filename),
            'yaml' => $this->importYaml($filename),
            default => throw new \RuntimeException("Unsupported format: {$this->options['format']}"),
        };
    }

    /**
     * Import permissions from an array
     */
    public function importFromArray(array $data, array $options = []): array
    {
        $this->options = array_merge([
            'batch_size' => 100,
            'skip_errors' => false,
            'dry_run' => false,
            'validate' => true,
        ], $options);

        $this->resetStats();

        // Validate structure
        if ($this->options['validate']) {
            $this->validateData($data);
        }

        // Process in batches
        $batches = array_chunk($data['permissions'] ?? $data, $this->options['batch_size']);

        foreach ($batches as $batch) {
            $this->processBatch($batch);
        }

        return $this->stats;
    }

    /**
     * Import from JSON file
     */
    protected function importJson(string $filename): array
    {
        $content = file_get_contents($filename);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        return $this->importFromArray($data);
    }

    /**
     * Import from CSV file
     */
    protected function importCsv(string $filename): array
    {
        $handle = fopen($filename, 'r');
        if (! $handle) {
            throw new \RuntimeException("Cannot open CSV file: {$filename}");
        }

        $headers = fgetcsv($handle);
        if (! $headers || ! in_array('user', $headers) || ! in_array('relation', $headers) || ! in_array('object', $headers)) {
            throw new \RuntimeException('CSV must have headers: user, relation, object');
        }

        $permissions = [];
        while (($row = fgetcsv($handle)) !== false) {
            $permission = array_combine($headers, $row);
            if ($permission) {
                $permissions[] = $permission;
            }
        }

        fclose($handle);

        return $this->importFromArray($permissions);
    }

    /**
     * Import from YAML file
     */
    protected function importYaml(string $filename): array
    {
        if (! function_exists('yaml_parse_file')) {
            throw new \RuntimeException('YAML extension not installed');
        }

        $data = yaml_parse_file($filename);
        if ($data === false) {
            throw new \RuntimeException('Invalid YAML file');
        }

        return $this->importFromArray($data);
    }

    /**
     * Process a batch of permissions
     */
    protected function processBatch(array $batch): void
    {
        $writes = [];

        foreach ($batch as $permission) {
            $this->stats['processed']++;

            try {
                // Validate permission
                if (! $this->validatePermission($permission)) {
                    $this->stats['skipped']++;
                    continue;
                }

                // Build write operation
                $writes[] = [
                    'user' => $permission['user'],
                    'relation' => $permission['relation'],
                    'object' => $permission['object'],
                ];

                $this->stats['imported']++;
            } catch (\Exception $e) {
                $this->stats['errors']++;

                if (! $this->options['skip_errors']) {
                    throw $e;
                }

                Log::warning('Permission import error', [
                    'permission' => $permission,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Execute writes if not dry run
        if (! empty($writes) && ! $this->options['dry_run']) {
            $this->manager->write($writes);
        }
    }

    /**
     * Validate permission data
     */
    protected function validatePermission(array $permission): bool
    {
        // Required fields
        if (! isset($permission['user']) || ! isset($permission['relation']) || ! isset($permission['object'])) {
            return false;
        }

        // Format validation
        if (! preg_match('/^[a-zA-Z0-9_-]+:[a-zA-Z0-9_-]+$/', $permission['user'])) {
            return false;
        }

        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $permission['relation'])) {
            return false;
        }

        if (! preg_match('/^[a-zA-Z0-9_-]+:[a-zA-Z0-9_-]+$/', $permission['object'])) {
            return false;
        }

        return true;
    }

    /**
     * Validate entire data structure
     */
    protected function validateData(array $data): void
    {
        // Check if it's wrapped in a permissions key
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            return;
        }

        // Check if it's a direct array of permissions
        if (isset($data[0]) && is_array($data[0])) {
            return;
        }

        throw new \RuntimeException('Invalid data structure. Expected array of permissions or object with permissions key.');
    }

    /**
     * Detect format from filename
     */
    protected function detectFormat(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'json' => 'json',
            'csv' => 'csv',
            'yml', 'yaml' => 'yaml',
            default => throw new \RuntimeException("Cannot detect format from extension: {$extension}"),
        };
    }

    /**
     * Reset statistics
     */
    protected function resetStats(): void
    {
        $this->stats = [
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
    }

    /**
     * Get import statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}