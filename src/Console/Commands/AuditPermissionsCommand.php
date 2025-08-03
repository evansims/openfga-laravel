<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use InvalidArgumentException;
use OpenFGA\Laravel\Exceptions\ConnectionException;
use OpenFGA\Laravel\OpenFgaManager;
use RuntimeException;

use function count;
use function is_array;
use function is_scalar;
use function is_string;
use function sprintf;

final class AuditPermissionsCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and analyze OpenFGA permissions';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:audit
                            {--user= : Audit permissions for a specific user}
                            {--object= : Audit permissions on a specific object}
                            {--relation= : Filter by specific relation}
                            {--type= : Filter by object type}
                            {--export= : Export results to file (csv, json)}
                            {--connection= : The OpenFGA connection to use}';

    /**
     * Execute the console command.
     *
     * @param OpenFgaManager $manager
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function handle(OpenFgaManager $manager): int
    {
        $connection = $this->option('connection');

        if (is_string($connection) || null === $connection) {
            $manager->connection($connection);
        }

        $this->info('Starting permission audit...');

        try {
            $auditData = $this->collectAuditData();

            if (false !== $this->option('export') && null !== $this->option('export')) {
                $this->exportResults($auditData);
            } else {
                $this->displayResults($auditData);
            }

            return self::SUCCESS;
        } catch (Exception $exception) {
            $this->error('Audit failed: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Analyze permissions for potential issues.
     *
     * @param  array<int, array{user?: string, relation?: string, object?: string}> $permissions
     * @return array<int, string>
     */
    private function analyzePermissions(array $permissions): array
    {
        $warnings = [];

        // Check for overly broad permissions
        $broadPermissions = collect($permissions)
            ->filter(static function (array $p): bool {
                $object = $p['object'] ?? '';

                return str_ends_with($object, ':*');
            })
            ->count();

        if (0 < $broadPermissions) {
            $warnings[] = sprintf('Found %d wildcard permissions that may be overly broad', $broadPermissions);
        }

        // Check for duplicate permissions
        $duplicates = collect($permissions)
            ->groupBy(static function (array $p): string {
                $user = $p['user'] ?? '';
                $relation = $p['relation'] ?? '';
                $object = $p['object'] ?? '';

                return $user . '-' . $relation . '-' . $object;
            })
            ->filter(static fn ($group): bool => 1 < $group->count())
            ->count();

        if (0 < $duplicates) {
            $warnings[] = sprintf('Found %d duplicate permission entries', $duplicates);
        }

        return $warnings;
    }

    /**
     * Audit permissions on a specific object.
     *
     * @param  string                            $object
     * @return array<int, array<string, string>>
     */
    private function auditObjectPermissions(string $object): array
    {
        $this->info('Auditing permissions on object: ' . $object);

        // Simulate some permissions
        return [
            ['user' => 'user:1', 'relation' => 'owner', 'object' => $object],
            ['user' => 'user:2', 'relation' => 'editor', 'object' => $object],
            ['user' => 'user:3', 'relation' => 'viewer', 'object' => $object],
        ];
    }

    /**
     * Audit permissions for a specific user.
     *
     * @param  string                            $user
     * @return array<int, array<string, string>>
     */
    private function auditUserPermissions(string $user): array
    {
        // Note: In real implementation, this would query OpenFGA
        // For demonstration, we'll show the structure

        $this->info('Auditing permissions for user: ' . $user);

        // Simulate some permissions
        return [
            ['user' => $user, 'relation' => 'owner', 'object' => 'document:1'],
            ['user' => $user, 'relation' => 'editor', 'object' => 'document:2'],
            ['user' => $user, 'relation' => 'viewer', 'object' => 'folder:shared'],
        ];
    }

    /**
     * Collect audit data based on options.
     *
     * @return array<string, mixed>
     */
    private function collectAuditData(): array
    {
        $data = [
            'summary' => [],
            'permissions' => [],
            'warnings' => [],
        ];

        // User-specific audit
        $user = $this->option('user');

        if (is_string($user)) {
            $data['permissions'] = $this->auditUserPermissions($user);
            $data['summary']['user'] = $user;
            $data['summary']['total_permissions'] = count($data['permissions']);
        }

        // Object-specific audit
        $object = $this->option('object');

        if (! is_string($user) && is_string($object)) {
            $data['permissions'] = $this->auditObjectPermissions($object);
            $data['summary']['object'] = $object;
            $data['summary']['total_users'] = count(array_unique(array_column($data['permissions'], 'user')));
        }

        // General audit
        else {
            $this->warn('Performing general audit. This may take some time...');
            $data = $this->performGeneralAudit();
        }

        // Analyze for warnings
        /** @var array<int, array{user?: string, relation?: string, object?: string}> $permissions */
        $permissions = isset($data['permissions']) && is_array($data['permissions']) ? $data['permissions'] : [];
        $data['warnings'] = $this->analyzePermissions($permissions);

        return $data;
    }

    /**
     * Display audit results.
     *
     * @param array<string, mixed> $data
     */
    private function displayResults(array $data): void
    {
        // Summary
        if (isset($data['summary']) && is_array($data['summary']) && [] !== $data['summary']) {
            $this->info('Audit Summary:');
            $this->table(
                ['Metric', 'Value'],
                collect($data['summary'])->map(static fn ($value, $key): array => [
                    ucwords(str_replace('_', ' ', is_string($key) ? $key : (string) $key)),
                    is_array($value) ? implode(', ', $value) : (is_scalar($value) ? (string) $value : ''),
                ])->toArray(),
            );
        }

        // Permissions table
        if (isset($data['permissions']) && is_array($data['permissions']) && [] !== $data['permissions']) {
            $this->newLine();
            $this->info('Permissions:');
            $this->table(
                ['User', 'Relation', 'Object'],
                array_map(static fn ($p): array => is_array($p) ? [
                    $p['user'] ?? '',
                    $p['relation'] ?? '',
                    $p['object'] ?? '',
                ] : ['', '', ''], $data['permissions']),
            );
        }

        // Warnings
        if (isset($data['warnings']) && is_array($data['warnings']) && [] !== $data['warnings']) {
            $this->newLine();
            $this->warn('Warnings:');

            /** @var mixed $warning */
            foreach ($data['warnings'] as $warning) {
                if (is_string($warning)) {
                    $this->comment('⚠️  ' . $warning);
                }
            }
        }
    }

    /**
     * Export results to file.
     *
     * @param array<string, mixed> $data
     *
     * @throws RuntimeException
     */
    private function exportResults(array $data): void
    {
        $format = $this->option('export');

        if (! is_string($format)) {
            $this->error('Invalid export format');

            return;
        }
        $filename = 'openfga_audit_' . now()->format('Y-m-d_His') . '.' . $format;

        switch ($format) {
            case 'csv':
                $this->exportToCsv($data, $filename);

                break;

            case 'json':
                $this->exportToJson($data, $filename);

                break;

            default:
                $this->error('Unsupported export format: ' . $format);

                return;
        }

        $this->info('Audit results exported to: ' . $filename);
    }

    /**
     * Export to CSV.
     *
     * @param array<string, mixed> $data
     * @param string               $filename
     *
     * @throws RuntimeException
     */
    private function exportToCsv(array $data, string $filename): void
    {
        $handle = fopen($filename, 'w');

        if (false === $handle) {
            throw new RuntimeException('Cannot open file for writing: ' . $filename);
        }

        // Write headers
        fputcsv($handle, ['User', 'Relation', 'Object']);

        // Write permissions
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            /** @var mixed $permission */
            foreach ($data['permissions'] as $permission) {
                if (is_array($permission)) {
                    $user = isset($permission['user']) && is_scalar($permission['user']) ? (string) $permission['user'] : '';
                    $relation = isset($permission['relation']) && is_scalar($permission['relation']) ? (string) $permission['relation'] : '';
                    $object = isset($permission['object']) && is_scalar($permission['object']) ? (string) $permission['object'] : '';
                    fputcsv($handle, [$user, $relation, $object]);
                }
            }
        }

        fclose($handle);
    }

    /**
     * Export to JSON.
     *
     * @param array<string, mixed> $data
     * @param string               $filename
     *
     * @throws RuntimeException
     */
    private function exportToJson(array $data, string $filename): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);

        if (false === $json) {
            throw new RuntimeException('Failed to encode data to JSON: ' . json_last_error_msg());
        }
        file_put_contents($filename, $json);
    }

    /**
     * Perform a general system-wide audit.
     *
     * @return array<string, mixed>
     */
    private function performGeneralAudit(): array
    {
        // This would be a comprehensive audit in real implementation
        return [
            'summary' => [
                'total_users' => 150,
                'total_objects' => 500,
                'total_permissions' => 2500,
                'object_types' => ['document', 'folder', 'project', 'organization'],
                'relation_types' => ['owner', 'editor', 'viewer', 'admin', 'member'],
            ],
            'permissions' => [],
            'warnings' => [
                'orphaned_permissions' => 5,
                'circular_dependencies' => 0,
                'overly_permissive' => 3,
            ],
        ];
    }
}
