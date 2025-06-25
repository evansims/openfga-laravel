<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\Testing\PermissionSnapshot;

use function count;
use function sprintf;

/**
 * Command to manage permission snapshots.
 */
final class SnapshotCommand extends Command
{
    protected $description = 'Manage permission snapshots for testing';

    protected $signature = 'openfga:snapshot
                            {action : The action to perform (list|show|delete|compare)}
                            {name? : The snapshot name}
                            {--name2= : Second snapshot name for comparison}
                            {--path= : Custom snapshot path}';

    public function handle(): int
    {
        $action = $this->argument('action');
        $name = $this->argument('name');
        $path = $this->option('path');

        $snapshot = new PermissionSnapshot($path);

        return match ($action) {
            'list' => $this->listSnapshots($snapshot),
            'show' => $this->showSnapshot($snapshot, $name),
            'delete' => $this->deleteSnapshot($snapshot, $name),
            'compare' => $this->compareSnapshots($snapshot, $name, $this->option('name2')),
            default => $this->invalidAction($action),
        };
    }

    private function compareSnapshots(PermissionSnapshot $snapshot, ?string $name1, ?string $name2): int
    {
        if (! $name1 || ! $name2) {
            $this->error('Two snapshot names are required for comparison.');

            return 1;
        }

        try {
            $report = $snapshot->generateDiffReport($name1, $name2);
            $this->line($report);

            return str_contains($report, 'No differences found') ? 0 : 1;
        } catch (Exception $exception) {
            $this->error('Failed to compare snapshots: ' . $exception->getMessage());

            return 1;
        }
    }

    private function deleteSnapshot(PermissionSnapshot $snapshot, ?string $name): int
    {
        if (! $name) {
            $this->error('Snapshot name is required for delete action.');

            return 1;
        }

        if (! $this->confirm(sprintf("Are you sure you want to delete snapshot '%s'?", $name))) {
            return 0;
        }

        if ($snapshot->deleteSnapshot($name)) {
            $this->info(sprintf("Snapshot '%s' deleted successfully.", $name));

            return 0;
        }

        $this->error(sprintf("Failed to delete snapshot '%s'.", $name));

        return 1;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while (1024 <= $bytes && $i < count($units) - 1) {
            $bytes /= 1024;
            ++$i;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function invalidAction(string $action): int
    {
        $this->error('Invalid action: ' . $action);
        $this->info('Valid actions are: list, show, delete, compare');

        return 1;
    }

    private function listSnapshots(PermissionSnapshot $snapshot): int
    {
        $snapshots = $snapshot->listSnapshots();

        if ($snapshots->isEmpty()) {
            $this->info('No snapshots found.');

            return 0;
        }

        $this->table(
            ['Name', 'Size', 'Last Modified'],
            $snapshots->map(fn ($s): array => [
                $s['name'],
                $this->formatBytes($s['size']),
                date('Y-m-d H:i:s', $s['modified']),
            ]),
        );

        return 0;
    }

    private function showObjectRelationships(array $relationships): void
    {
        $this->info('Object Relationships:');

        foreach ($relationships as $object => $relations) {
            $this->info('  Object: ' . $object);

            foreach ($relations as $relation => $users) {
                if (null === $users) {
                    $this->line(sprintf('    %s: (not available)', $relation));
                } elseif (empty($users)) {
                    $this->line(sprintf('    %s: (none)', $relation));
                } else {
                    $this->line(sprintf('    %s: ', $relation) . implode(', ', $users));
                }
            }
        }

        $this->newLine();
    }

    private function showPermissionMatrix(array $matrix): void
    {
        $this->info('Permission Matrix:');

        $allowed = collect($matrix)->filter(static fn ($v) => $v)->keys();
        $denied = collect($matrix)->filter(static fn ($v): bool => ! $v)->keys();

        if ($allowed->isNotEmpty()) {
            $this->info('  Allowed:');

            foreach ($allowed as $key) {
                $this->line('    - ' . $key);
            }
        }

        if ($denied->isNotEmpty()) {
            $this->info('  Denied:');

            foreach ($denied as $key) {
                $this->line('    - ' . $key);
            }
        }

        $this->newLine();
    }

    private function showSnapshot(PermissionSnapshot $snapshot, ?string $name): int
    {
        if (! $name) {
            $this->error('Snapshot name is required for show action.');

            return 1;
        }

        try {
            $data = $snapshot->loadSnapshot($name);

            $this->info('Snapshot: ' . $name);
            $this->info('Created: ' . $data['timestamp']);
            $this->newLine();

            if (isset($data['snapshot']['user_permissions'])) {
                $this->showUserPermissions($data['snapshot']['user_permissions']);
            }

            if (isset($data['snapshot']['permission_matrix'])) {
                $this->showPermissionMatrix($data['snapshot']['permission_matrix']);
            }

            if (isset($data['snapshot']['object_relationships'])) {
                $this->showObjectRelationships($data['snapshot']['object_relationships']);
            }

            return 0;
        } catch (Exception $exception) {
            $this->error('Failed to load snapshot: ' . $exception->getMessage());

            return 1;
        }
    }

    private function showUserPermissions(array $permissions): void
    {
        $this->info('User Permissions:');

        foreach ($permissions as $user => $perms) {
            $this->info('  User: ' . $user);

            $allowed = collect($perms)->filter(static fn ($p): mixed => $p['allowed']);
            $denied = collect($perms)->filter(static fn ($p): bool => ! $p['allowed']);

            if ($allowed->isNotEmpty()) {
                $this->info('    Allowed:');

                foreach ($allowed as $perm) {
                    $this->line(sprintf('      - %s on %s', $perm['relation'], $perm['object']));
                }
            }

            if ($denied->isNotEmpty()) {
                $this->info('    Denied:');

                foreach ($denied as $perm) {
                    $this->line(sprintf('      - %s on %s', $perm['relation'], $perm['object']));
                }
            }
        }

        $this->newLine();
    }
}
