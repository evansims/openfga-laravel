<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;

use function count;
use function is_array;
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
     */
    public function handle(OpenFgaManager $manager): int
    {
        $connection = $this->option('connection');
        $manager->connection($connection);

        $this->info('Starting permission audit...');

        try {
            $auditData = $this->collectAuditData();

            if ($this->option('export')) {
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
     * @param array $permissions
     */
    private function analyzePermissions(array $permissions): array
    {
        $warnings = [];

        // Check for overly broad permissions
        $broadPermissions = collect($permissions)
            ->filter(static fn ($p): bool => str_ends_with($p['object'] ?? '', ':*'))
            ->count();

        if (0 < $broadPermissions) {
            $warnings[] = sprintf('Found %d wildcard permissions that may be overly broad', $broadPermissions);
        }

        // Check for duplicate permissions
        $duplicates = collect($permissions)
            ->groupBy(static fn ($p): string => ($p['user'] ?? '') . '-' . ($p['relation'] ?? '') . '-' . ($p['object'] ?? ''))
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
     * @param string $object
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
     * @param string $user
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
     */
    private function collectAuditData(): array
    {
        $data = [
            'summary' => [],
            'permissions' => [],
            'warnings' => [],
        ];

        // User-specific audit
        if ($user = $this->option('user')) {
            $data['permissions'] = $this->auditUserPermissions($user);
            $data['summary']['user'] = $user;
            $data['summary']['total_permissions'] = count($data['permissions']);
        }

        // Object-specific audit
        elseif ($object = $this->option('object')) {
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
        $data['warnings'] = $this->analyzePermissions($data['permissions']);

        return $data;
    }

    /**
     * Display audit results.
     *
     * @param array $data
     */
    private function displayResults(array $data): void
    {
        // Summary
        if (! empty($data['summary'])) {
            $this->info('Audit Summary:');
            $this->table(
                ['Metric', 'Value'],
                collect($data['summary'])->map(static fn ($value, $key): array => [
                    ucwords(str_replace('_', ' ', $key)),
                    is_array($value) ? implode(', ', $value) : $value,
                ])->toArray(),
            );
        }

        // Permissions table
        if (! empty($data['permissions'])) {
            $this->newLine();
            $this->info('Permissions:');
            $this->table(
                ['User', 'Relation', 'Object'],
                $data['permissions'],
            );
        }

        // Warnings
        if (! empty($data['warnings'])) {
            $this->newLine();
            $this->warn('Warnings:');

            foreach ($data['warnings'] as $warning) {
                $this->comment('⚠️  ' . $warning);
            }
        }
    }

    /**
     * Export results to file.
     *
     * @param array $data
     */
    private function exportResults(array $data): void
    {
        $format = $this->option('export');
        $filename = 'openfga_audit_' . now()->format('Y-m-d_His') . ('.' . $format);

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
     * @param array  $data
     * @param string $filename
     */
    private function exportToCsv(array $data, string $filename): void
    {
        $handle = fopen($filename, 'w');

        // Write headers
        fputcsv($handle, ['User', 'Relation', 'Object']);

        // Write permissions
        foreach ($data['permissions'] as $permission) {
            fputcsv($handle, [
                $permission['user'] ?? '',
                $permission['relation'] ?? '',
                $permission['object'] ?? '',
            ]);
        }

        fclose($handle);
    }

    /**
     * Export to JSON.
     *
     * @param array  $data
     * @param string $filename
     */
    private function exportToJson(array $data, string $filename): void
    {
        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Perform a general system-wide audit.
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
