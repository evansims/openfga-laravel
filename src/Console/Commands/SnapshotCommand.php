<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\Testing\PermissionSnapshot;
use OpenFGA\Results\{Failure, Success};

use function count;
use function is_array;
use function is_scalar;
use function is_string;
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

        if (! is_string($action)) {
            $this->error('Action must be a string.');

            return 1;
        }

        $nameString = is_string($name) ? $name : null;
        $pathString = is_string($path) ? $path : null;

        $snapshot = new PermissionSnapshot($pathString);

        $name2 = $this->option('name2');
        $name2String = is_string($name2) ? $name2 : null;

        return match ($action) {
            'list' => $this->listSnapshots($snapshot),
            'show' => $this->showSnapshot($snapshot, $nameString),
            'delete' => $this->deleteSnapshot($snapshot, $nameString),
            'compare' => $this->compareSnapshots($snapshot, $nameString, $name2String),
            default => $this->invalidAction($action),
        };
    }

    private function compareSnapshots(PermissionSnapshot $snapshot, ?string $name1, ?string $name2): int
    {
        if (null === $name1 || null === $name2) {
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
        if (null === $name) {
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
        $size = (float) $bytes;

        while (1024 <= $size && $i < count($units) - 1) {
            $size /= 1024.0;
            ++$i;
        }

        /** @var int<0, 3> $i */
        return number_format($size, 2) . ' ' . $units[$i];
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
            ])->toArray(),
        );

        return 0;
    }

    /**
     * @param array<string, array<string, mixed>> $relationships
     */
    private function showObjectRelationships(array $relationships): void
    {
        $this->info('Object Relationships:');

        foreach ($relationships as $object => $relations) {
            $this->info('  Object: ' . $object);

            /**
             * @var mixed $users
             */
            foreach ($relations as $relation => $users) {
                if (null === $users) {
                    $this->line(sprintf('    %s: (not available)', $relation));
                } elseif (is_array($users) && [] === $users) {
                    $this->line(sprintf('    %s: (none)', $relation));
                } elseif (is_array($users)) {
                    $userStrings = array_map(static fn ($u): string => is_scalar($u) ? (string) $u : '', $users);
                    $this->line(sprintf('    %s: ', $relation) . implode(', ', $userStrings));
                } else {
                    $this->line(sprintf('    %s: (invalid data)', $relation));
                }
            }
        }

        $this->newLine();
    }

    /**
     * @param array<string, Failure|Success> $matrix
     */
    private function showPermissionMatrix(array $matrix): void
    {
        $this->info('Permission Matrix:');

        $allowed = collect($matrix)->filter(static fn ($v): bool => $v->succeeded())->keys();
        $denied = collect($matrix)->filter(static fn ($v): bool => $v->failed())->keys();

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
        if (null === $name) {
            $this->error('Snapshot name is required for show action.');

            return 1;
        }

        try {
            $data = $snapshot->loadSnapshot($name);

            $this->info('Snapshot: ' . $name);
            $timestamp = isset($data['timestamp']) && is_scalar($data['timestamp']) ? (string) $data['timestamp'] : 'Unknown';
            $this->info('Created: ' . $timestamp);
            $this->newLine();

            if (isset($data['snapshot']) && is_array($data['snapshot'])) {
                $snapshot = $data['snapshot'];

                if (isset($snapshot['user_permissions']) && is_array($snapshot['user_permissions'])) {
                    /** @var array<string, array<int, array{user: string, relation: string, object: string, allowed: Failure|Success}>> $userPermissions */
                    $userPermissions = $snapshot['user_permissions'];
                    $this->showUserPermissions($userPermissions);
                }

                if (isset($snapshot['permission_matrix']) && is_array($snapshot['permission_matrix'])) {
                    /** @var array<string, Failure|Success> $permissionMatrix */
                    $permissionMatrix = $snapshot['permission_matrix'];
                    $this->showPermissionMatrix($permissionMatrix);
                }

                if (isset($snapshot['object_relationships']) && is_array($snapshot['object_relationships'])) {
                    /** @var array<string, array<string, mixed>> $objectRelationships */
                    $objectRelationships = $snapshot['object_relationships'];
                    $this->showObjectRelationships($objectRelationships);
                }
            }

            return 0;
        } catch (Exception $exception) {
            $this->error('Failed to load snapshot: ' . $exception->getMessage());

            return 1;
        }
    }

    /**
     * @param array<string, array<int, array{user: string, relation: string, object: string, allowed: Failure|Success}>> $permissions
     */
    private function showUserPermissions(array $permissions): void
    {
        $this->info('User Permissions:');

        foreach ($permissions as $user => $perms) {
            $this->info('  User: ' . $user);

            $allowed = collect($perms)->filter(static fn (array $p): bool => $p['allowed']->succeeded());
            $denied = collect($perms)->filter(static fn (array $p): bool => $p['allowed']->failed());

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
