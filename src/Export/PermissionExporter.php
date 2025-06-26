<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Export;

use RuntimeException;

use function count;
use function function_exists;

final class PermissionExporter
{
    /**
     * @var array{format: string, include_metadata: bool, pretty_print: bool, chunk_size?: int|null}
     */
    private array $options = [
        'format' => 'json',
        'include_metadata' => true,
        'pretty_print' => true,
        'chunk_size' => null,
    ];

    /**
     * Export permissions to array.
     *
     * @param  array{users?: array<int, string>, relations?: array<int, string>, objects?: array<int, string>} $filters
     * @return array<mixed>
     */
    public function exportToArray(array $filters = []): array
    {
        $permissions = $this->collectPermissions($filters);

        if ($this->options['include_metadata'] ?? true) {
            return [
                'metadata' => [
                    'exported_at' => now()->toIso8601String(),
                    'total' => count($permissions),
                    'filters' => $filters,
                    'application' => config('app.name'),
                    'environment' => app()->environment(),
                ],
                'permissions' => $permissions,
            ];
        }

        return $permissions;
    }

    /**
     * Export permissions to a file.
     *
     * @param string                                                                                                                                                                   $filename
     * @param array{user?: string, object?: string, object_type?: string, relation?: string, users?: array<int, string>, relations?: array<int, string>, objects?: array<int, string>} $filters
     * @param array{include_metadata?: bool, format?: string, chunk_size?: int, pretty_print?: bool}                                                                                   $options
     */
    public function exportToFile(string $filename, array $filters = [], array $options = []): int
    {
        /** @var array{format: string, include_metadata: bool, pretty_print: bool, chunk_size?: int} $options */
        $this->options = [
            'format' => $options['format'] ?? $this->detectFormat($filename),
            'include_metadata' => $options['include_metadata'] ?? true,
            'pretty_print' => $options['pretty_print'] ?? true,
            'chunk_size' => $options['chunk_size'] ?? null,
        ];

        $permissions = $this->collectPermissions($filters);
        $count = count($permissions);

        $content = match ($this->options['format']) {
            'json' => $this->exportJson($permissions, $filters),
            'csv' => $this->exportCsv($permissions),
            'yaml' => $this->exportYaml($permissions, $filters),
            default => throw new RuntimeException('Unsupported format: ' . $this->options['format']),
        };

        file_put_contents($filename, $content);

        return $count;
    }

    /**
     * Collect permissions based on filters.
     *
     * @param  array{user?: string, object?: string, object_type?: string, relation?: string, users?: array<int, string>, relations?: array<int, string>, objects?: array<int, string>} $filters
     * @return array<int, array{user: string, relation: string, object: string}>
     */
    private function collectPermissions(array $filters): array
    {
        // Note: In a real implementation, this would query OpenFGA
        // For now, we'll simulate with example data

        // Filter by user
        if (isset($filters['user'])) {
            // Would query: listObjects for specific user
            $permissions = $this->getPermissionsForUser($filters['user']);
        }
        // Filter by object
        elseif (isset($filters['object'])) {
            // Would query: listUsers for specific object
            $permissions = $this->getPermissionsForObject($filters['object']);
        }
        // Filter by object type
        elseif (isset($filters['object_type'])) {
            // Would query: all objects of type
            $permissions = $this->getPermissionsForObjectType($filters['object_type']);
        }
        // Export all (use with caution)
        else {
            $permissions = $this->getAllPermissions();
        }

        // Apply additional filters
        if (isset($filters['relation'])) {
            $permissions = array_filter($permissions, static fn (array $p): bool => $p['relation'] === $filters['relation']);
        }

        return array_values($permissions);
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
     * Export to CSV format.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $permissions
     */
    /**
     * @param array<int, array{user: string, relation: string, object: string}> $permissions
     *
     * @throws RuntimeException
     */
    private function exportCsv(array $permissions): string
    {
        $output = fopen('php://temp', 'r+');

        if (false === $output) {
            throw new RuntimeException('Failed to create temporary file');
        }

        // Write headers
        fputcsv($output, ['user', 'relation', 'object']);

        // Write data
        foreach ($permissions as $permission) {
            fputcsv($output, [
                $permission['user'],
                $permission['relation'],
                $permission['object'],
            ]);
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return false !== $content ? $content : '';
    }

    /**
     * Export to JSON format.
     *
     * @param array<int, array{user: string, relation: string, object: string}>                                                                                                        $permissions
     * @param array{user?: string, object?: string, object_type?: string, relation?: string, users?: array<int, string>, relations?: array<int, string>, objects?: array<int, string>} $filters
     *
     * @throws RuntimeException
     */
    private function exportJson(array $permissions, array $filters = []): string
    {
        $data = $this->options['include_metadata']
            ? [
                'metadata' => [
                    'exported_at' => now()->toIso8601String(),
                    'total' => count($permissions),
                    'filters' => $filters,
                    'application' => config('app.name'),
                    'environment' => app()->environment(),
                ],
                'permissions' => $permissions,
            ]
            : $permissions;

        $flags = $this->options['pretty_print']
            ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            : 0;

        $encoded = json_encode($data, $flags);

        if (false === $encoded) {
            throw new RuntimeException('Failed to encode JSON');
        }

        return $encoded;
    }

    /**
     * Export to YAML format.
     *
     * @param array<int, array{user: string, relation: string, object: string}>                                                                                                        $permissions
     * @param array{user?: string, object?: string, object_type?: string, relation?: string, users?: array<int, string>, relations?: array<int, string>, objects?: array<int, string>} $filters
     *
     * @throws RuntimeException
     */
    private function exportYaml(array $permissions, array $filters = []): string
    {
        if (! function_exists('yaml_emit')) {
            throw new RuntimeException('YAML extension not installed');
        }

        $data = $this->options['include_metadata']
            ? [
                'metadata' => [
                    'exported_at' => now()->toIso8601String(),
                    'total' => count($permissions),
                    'filters' => $filters,
                    'application' => config('app.name'),
                    'environment' => app()->environment(),
                ],
                'permissions' => $permissions,
            ]
            : ['permissions' => $permissions];

        return yaml_emit($data);
    }

    /**
     * Simulate getting all permissions (dangerous in production).
     *
     * @return array<int, array{user: string, relation: string, object: string}>
     */
    private function getAllPermissions(): array
    {
        // This would be very expensive in production
        return [
            ['user' => 'user:1', 'relation' => 'owner', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'editor', 'object' => 'document:1'],
            ['user' => 'user:3', 'relation' => 'viewer', 'object' => 'document:1'],
        ];
    }

    /**
     * Simulate getting permissions for an object.
     *
     * @param  string                                                            $object
     * @return array<int, array{user: string, relation: string, object: string}>
     */
    private function getPermissionsForObject(string $object): array
    {
        // In real implementation, would use listUsers
        return [
            ['user' => 'user:1', 'relation' => 'owner', 'object' => $object],
            ['user' => 'user:2', 'relation' => 'editor', 'object' => $object],
            ['user' => 'user:3', 'relation' => 'viewer', 'object' => $object],
        ];
    }

    /**
     * Simulate getting permissions for an object type.
     *
     * @param  string                                                            $type
     * @return array<int, array{user: string, relation: string, object: string}>
     */
    private function getPermissionsForObjectType(string $type): array
    {
        // In real implementation, would query all objects of type
        return [
            ['user' => 'user:1', 'relation' => 'owner', 'object' => $type . ':1'],
            ['user' => 'user:1', 'relation' => 'owner', 'object' => $type . ':2'],
            ['user' => 'user:2', 'relation' => 'editor', 'object' => $type . ':1'],
        ];
    }

    /**
     * Simulate getting permissions for a user.
     *
     * @param  string                                                            $user
     * @return array<int, array{user: string, relation: string, object: string}>
     */
    private function getPermissionsForUser(string $user): array
    {
        // In real implementation, would use listObjects
        return [
            ['user' => $user, 'relation' => 'owner', 'object' => 'document:1'],
            ['user' => $user, 'relation' => 'editor', 'object' => 'document:2'],
            ['user' => $user, 'relation' => 'viewer', 'object' => 'folder:shared'],
        ];
    }
}
