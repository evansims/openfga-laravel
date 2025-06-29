<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Import;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Models\Collections\TupleKeys;
use OpenFGA\Models\TupleKey;
use ReflectionException;
use RuntimeException;

use function count;
use function function_exists;
use function in_array;
use function is_array;
use function is_string;

final class PermissionImporter
{
    /**
     * @var array{batch_size: int, dry_run: bool, skip_duplicates: bool, validate: bool, progress?: (callable(): mixed)|null}
     */
    private array $options = [
        'batch_size' => 100,
        'dry_run' => false,
        'skip_duplicates' => true,
        'validate' => true,
        'progress' => null,
    ];

    /**
     * @var array{processed: int, imported: int, skipped: int, errors: int}
     */
    private array $stats = [
        'processed' => 0,
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    public function __construct(private readonly ManagerInterface $manager)
    {
    }

    /**
     * Get import statistics.
     *
     * @return array{processed: int, imported: int, skipped: int, errors: int}
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Import permissions from an array.
     *
     * @param array<int, array{user: string, relation: string, object: string}>|array{permissions: array<int, array{user: string, relation: string, object: string}>} $data
     * @param array{batch_size?: int, dry_run?: bool, skip_duplicates?: bool, validate?: bool, progress?: (callable(): mixed)|null}                                   $options
     *
     * @throws RuntimeException
     *
     * @return array{processed: int, imported: int, skipped: int, errors: int}
     */
    public function importFromArray(array $data, array $options = []): array
    {
        $this->options = [
            'batch_size' => $options['batch_size'] ?? 100,
            'dry_run' => $options['dry_run'] ?? false,
            'skip_duplicates' => $options['skip_duplicates'] ?? true,
            'validate' => $options['validate'] ?? true,
            'progress' => $options['progress'] ?? null,
        ];

        $this->resetStats();

        // Validate structure
        if ($this->options['validate']) {
            $this->validateData($data);
        }

        // Process in batches
        /** @var array<int, array{user: string, relation: string, object: string}> $permissions */
        $permissions = $data['permissions'] ?? $data;
        $batchSize = max(1, $this->options['batch_size']);
        $batches = array_chunk($permissions, $batchSize);

        foreach ($batches as $batch) {
            $this->processBatch($batch);
        }

        return $this->stats;
    }

    /**
     * Import permissions from a file.
     *
     * @param string                                                                                                                                                                            $filename
     * @param array{format?: string, batch_size?: int, skip_errors?: bool, dry_run?: bool, clear_existing?: bool, validate?: bool, skip_duplicates?: bool, progress?: (callable(): mixed)|null} $options
     *
     * @throws RuntimeException
     *
     * @return array{processed: int, imported: int, skipped: int, errors: int}
     */
    public function importFromFile(string $filename, array $options = []): array
    {
        $format = $options['format'] ?? $this->detectFormat($filename);

        $this->options = [
            'batch_size' => $options['batch_size'] ?? 100,
            'dry_run' => $options['dry_run'] ?? false,
            'skip_duplicates' => $options['skip_duplicates'] ?? true,
            'validate' => $options['validate'] ?? true,
            'progress' => $options['progress'] ?? null,
        ];

        // Store additional options separately for this method
        $fileOptions = [
            'format' => $format,
            'skip_errors' => $options['skip_errors'] ?? false,
            'clear_existing' => $options['clear_existing'] ?? false,
        ];

        $this->resetStats();

        if (! file_exists($filename)) {
            throw new RuntimeException('Import file not found: ' . $filename);
        }

        return match ($fileOptions['format']) {
            'json' => $this->importJson($filename),
            'csv' => $this->importCsv($filename),
            'yaml' => $this->importYaml($filename),
            default => throw new RuntimeException('Unsupported format: ' . $fileOptions['format']),
        };
    }

    /**
     * Detect format from filename.
     *
     * @param string $filename
     */
    private function detectFormat(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'json' => 'json',
            'csv' => 'csv',
            'yml', 'yaml' => 'yaml',
            default => throw new RuntimeException('Cannot detect format from extension: ' . $extension),
        };
    }

    /**
     * Import from CSV file.
     *
     * @param string $filename
     *
     * @throws RuntimeException
     *
     * @return array{processed: int, imported: int, skipped: int, errors: int}
     */
    private function importCsv(string $filename): array
    {
        $handle = fopen($filename, 'r');

        if (false === $handle) {
            throw new RuntimeException('Cannot open CSV file: ' . $filename);
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers)) {
            throw new RuntimeException('Cannot read CSV headers');
        }

        /** @var list<string|null> $headers */

        /** @var list<string> $mappedHeaders */
        $mappedHeaders = [];

        foreach ($headers as $header) {
            $mappedHeaders[] = (string) $header;
        }

        /** @var array<int, string> $headerStrings */
        $headerStrings = $mappedHeaders;

        if (! in_array('user', $headerStrings, true) || ! in_array('relation', $headerStrings, true) || ! in_array('object', $headerStrings, true)) {
            throw new RuntimeException('CSV must have headers: user, relation, object');
        }

        /** @var array<int, array{user: string, relation: string, object: string}> $permissions */
        $permissions = [];

        while (($row = fgetcsv($handle)) !== false) {
            /** @var list<string|null> $row - We know it's not false here */
            // Ensure row has same number of elements as headers
            if (count($headerStrings) !== count($row)) {
                continue; // Skip malformed rows
            }

            /** @var array<int, string> $rowStrings */
            $rowStrings = array_map(static fn (mixed $value): string => (string) $value, $row);

            $permission = array_combine($headerStrings, $rowStrings);

            // Validate required fields
            if (isset($permission['user'], $permission['relation'], $permission['object'])
            ) {
                $permissions[] = [
                    'user' => $permission['user'],
                    'relation' => $permission['relation'],
                    'object' => $permission['object'],
                ];
            }
        }

        fclose($handle);

        return $this->importFromArray($permissions);
    }

    /**
     * Import from JSON file.
     *
     * @param string $filename
     *
     * @throws RuntimeException
     *
     * @return array{processed: int, imported: int, skipped: int, errors: int}
     */
    private function importJson(string $filename): array
    {
        $content = file_get_contents($filename);

        if (false === $content) {
            throw new RuntimeException('Cannot read file: ' . $filename);
        }

        $data = json_decode($content, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        if (! is_array($data)) {
            throw new RuntimeException('Invalid JSON data structure');
        }

        /** @var array<int, array{user: string, relation: string, object: string}> $permissionData */
        $permissionData = [];

        // Check if data has permissions key
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            /** @var mixed $perm */
            foreach ($data['permissions'] as $perm) {
                if (is_array($perm)
                    && isset($perm['user']) && is_string($perm['user'])
                    && isset($perm['relation']) && is_string($perm['relation'])
                    && isset($perm['object']) && is_string($perm['object'])) {
                    $permissionData[] = [
                        'user' => $perm['user'],
                        'relation' => $perm['relation'],
                        'object' => $perm['object'],
                    ];
                }
            }
        } else {
            // Direct array of permissions
            /** @var mixed $perm */
            foreach ($data as $perm) {
                if (is_array($perm)
                    && isset($perm['user']) && is_string($perm['user'])
                    && isset($perm['relation']) && is_string($perm['relation'])
                    && isset($perm['object']) && is_string($perm['object'])) {
                    $permissionData[] = [
                        'user' => $perm['user'],
                        'relation' => $perm['relation'],
                        'object' => $perm['object'],
                    ];
                }
            }
        }

        return $this->importFromArray($permissionData);
    }

    /**
     * Import from YAML file.
     *
     * @param string $filename
     *
     * @throws RuntimeException
     *
     * @return array{processed: int, imported: int, skipped: int, errors: int}
     */
    private function importYaml(string $filename): array
    {
        if (! function_exists('yaml_parse_file')) {
            throw new RuntimeException('YAML extension not installed');
        }

        $data = yaml_parse_file($filename);

        if (false === $data) {
            throw new RuntimeException('Invalid YAML file');
        }

        if (! is_array($data)) {
            throw new RuntimeException('Invalid YAML data structure');
        }

        /** @var array<int, array{user: string, relation: string, object: string}> $permissionData */
        $permissionData = [];

        // Check if data has permissions key
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            /** @var mixed $perm */
            foreach ($data['permissions'] as $perm) {
                if (is_array($perm)
                    && isset($perm['user']) && is_string($perm['user'])
                    && isset($perm['relation']) && is_string($perm['relation'])
                    && isset($perm['object']) && is_string($perm['object'])) {
                    $permissionData[] = [
                        'user' => $perm['user'],
                        'relation' => $perm['relation'],
                        'object' => $perm['object'],
                    ];
                }
            }
        } else {
            // Direct array of permissions
            /** @var mixed $perm */
            foreach ($data as $perm) {
                if (is_array($perm)
                    && isset($perm['user']) && is_string($perm['user'])
                    && isset($perm['relation']) && is_string($perm['relation'])
                    && isset($perm['object']) && is_string($perm['object'])) {
                    $permissionData[] = [
                        'user' => $perm['user'],
                        'relation' => $perm['relation'],
                        'object' => $perm['object'],
                    ];
                }
            }
        }

        return $this->importFromArray($permissionData);
    }

    /**
     * Process a batch of permissions.
     *
     * @param array<int, mixed> $batch
     *
     * @throws BindingResolutionException|ClientThrowable|Exception|InvalidArgumentException|ReflectionException
     */
    private function processBatch(array $batch): void
    {
        /** @var array<int, TupleKey> $tuples */
        $tuples = [];

        foreach ($batch as $permission) {
            ++$this->stats['processed'];

            try {
                // Skip non-array items
                if (! is_array($permission)) {
                    ++$this->stats['skipped'];

                    continue;
                }

                // Validate permission
                if (! $this->validatePermission($permission)) {
                    ++$this->stats['skipped'];

                    continue;
                }

                // Ensure values are strings - validation guarantees they exist and are valid
                $user = isset($permission['user']) && is_string($permission['user']) ? $permission['user'] : '';
                $relation = isset($permission['relation']) && is_string($permission['relation']) ? $permission['relation'] : '';
                $object = isset($permission['object']) && is_string($permission['object']) ? $permission['object'] : '';

                // Skip if any value is empty (should not happen after validation)
                if ('' === $user || '' === $relation || '' === $object) {
                    ++$this->stats['skipped'];

                    continue;
                }

                // Build TupleKey
                $tuples[] = new TupleKey(
                    user: $user,
                    relation: $relation,
                    object: $object,
                );

                ++$this->stats['imported'];
            } catch (Exception $e) {
                ++$this->stats['errors'];

                // Always throw errors
                throw $e;
            }
        }

        // Execute writes if not dry run
        if ([] !== $tuples && ! $this->options['dry_run']) {
            $writes = new TupleKeys($tuples);
            $this->manager->write($writes);
        }
    }

    /**
     * Reset statistics.
     */
    private function resetStats(): void
    {
        $this->stats = [
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
    }

    /**
     * Validate entire data structure.
     *
     * @param array<mixed> $data
     *
     * @throws RuntimeException
     */
    private function validateData(array $data): void
    {
        // Check if it's wrapped in a permissions key
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            return;
        }

        // Check if it's a direct array of permissions (can be empty)
        if ([] === $data || (isset($data[0]) && is_array($data[0]))) {
            return;
        }

        throw new RuntimeException('Invalid data structure. Expected array of permissions or object with permissions key.');
    }

    /**
     * Validate permission data.
     *
     * @param array<mixed, mixed> $permission
     */
    private function validatePermission(array $permission): bool
    {
        // Required fields
        if (! isset($permission['user']) || ! isset($permission['relation']) || ! isset($permission['object'])) {
            return false;
        }

        // Format validation
        $user = is_string($permission['user']) ? $permission['user'] : '';
        $relation = is_string($permission['relation']) ? $permission['relation'] : '';
        $object = is_string($permission['object']) ? $permission['object'] : '';

        if ('' === $user || ! str_contains($user, ':')) {
            return false;
        }

        if ('' === $relation || 1 !== preg_match('/^[a-zA-Z0-9_-]+$/', $relation)) {
            return false;
        }

        return '' !== $object && str_contains($object, ':');
    }
}
