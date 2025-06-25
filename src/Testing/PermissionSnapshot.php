<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use OpenFGA\Laravel\Facades\OpenFga;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

use function sprintf;

/**
 * Permission snapshot testing utility.
 *
 * Allows capturing and comparing permission states over time
 * to ensure consistency and detect unintended changes.
 */
final class PermissionSnapshot
{
    private readonly string $snapshotPath;

    private array $currentSnapshot = [];

    private bool $updateSnapshots = false;

    public function __construct(?string $snapshotPath = null)
    {
        $this->snapshotPath = $snapshotPath ?? storage_path('testing/permission-snapshots');
        $this->updateSnapshots = env('UPDATE_PERMISSION_SNAPSHOTS', false);

        $this->ensureSnapshotDirectoryExists();
    }

    /**
     * Assert that current permissions match the saved snapshot.
     *
     * @param string $name
     */
    public function assertMatchesSnapshot(string $name): void
    {
        $snapshotFile = $this->getSnapshotPath($name);

        if ($this->updateSnapshots || ! File::exists($snapshotFile)) {
            $this->saveSnapshot($name);

            if (! File::exists($snapshotFile)) {
                throw new RuntimeException('Failed to save snapshot: ' . $name);
            }

            return;
        }

        $savedSnapshot = json_decode(File::get($snapshotFile), true);
        $differences = $this->compareSnapshots($savedSnapshot, $this->currentSnapshot);

        if ([] !== $differences) {
            $message = "Permission snapshot '{$name}' does not match:\n";
            $message .= $this->formatDifferences($differences);
            $message .= "\n\nTo update snapshots, run tests with UPDATE_PERMISSION_SNAPSHOTS=true";

            throw new AssertionFailedError($message);
        }
    }

    /**
     * Capture inheritance hierarchy for an object.
     *
     * @param string $user
     * @param string $object
     */
    public function captureInheritanceTree(string $user, string $object): self
    {
        try {
            $tree = OpenFga::expand($object);
            $this->currentSnapshot['inheritance_trees'][$user][$object] = $this->serializeTree($tree);
        } catch (Exception) {
            $this->currentSnapshot['inheritance_trees'][$user][$object] = null;
        }

        return $this;
    }

    /**
     * Capture all permissions for a set of users and objects.
     *
     * @param array $users
     * @param array $objects
     * @param array $relations
     */
    public function captureMatrix(array $users, array $objects, array $relations): self
    {
        $matrix = [];

        foreach ($users as $user) {
            foreach ($objects as $object) {
                foreach ($relations as $relation) {
                    $key = sprintf('%s:%s:%s', $user, $relation, $object);
                    $matrix[$key] = OpenFga::check($user, $relation, $object);
                }
            }
        }

        $this->currentSnapshot['permission_matrix'] = $matrix;

        return $this;
    }

    /**
     * Capture object relationships (who has access to what).
     *
     * @param string $object
     * @param array  $relations
     */
    public function captureObjectRelationships(string $object, array $relations): self
    {
        $relationships = [];

        foreach ($relations as $relation) {
            try {
                $users = OpenFga::listUsers($object, $relation);
                $relationships[$relation] = $users;
            } catch (Exception) {
                // If listing users is not supported, skip
                $relationships[$relation] = null;
            }
        }

        $this->currentSnapshot['object_relationships'][$object] = $relationships;

        return $this;
    }

    /**
     * Capture user's accessible objects.
     *
     * @param string $userId
     * @param string $relation
     * @param string $objectType
     */
    public function captureUserAccessibleObjects(string $userId, string $relation, string $objectType): self
    {
        try {
            $objects = OpenFga::listObjects($userId, $relation, $objectType);
            $this->currentSnapshot['user_accessible_objects'][$userId][$relation][$objectType] = $objects;
        } catch (Exception) {
            // If listing objects is not supported, skip
            $this->currentSnapshot['user_accessible_objects'][$userId][$relation][$objectType] = null;
        }

        return $this;
    }

    /**
     * Capture permissions for a specific user.
     *
     * @param string $userId
     * @param array  $objects
     * @param array  $relations
     */
    public function captureUserPermissions(string $userId, array $objects, array $relations): self
    {
        $permissions = [];

        foreach ($objects as $object) {
            foreach ($relations as $relation) {
                $allowed = OpenFga::check($userId, $relation, $object);
                $permissions[] = [
                    'user' => $userId,
                    'relation' => $relation,
                    'object' => $object,
                    'allowed' => $allowed,
                ];
            }
        }

        $this->currentSnapshot['user_permissions'][$userId] = $permissions;

        return $this;
    }

    /**
     * Clear current snapshot data.
     */
    public function clear(): self
    {
        $this->currentSnapshot = [];

        return $this;
    }

    /**
     * Compare two snapshots and return differences.
     *
     * @param array $saved
     * @param array $current
     */
    public function compareSnapshots(array $saved, array $current): array
    {
        $differences = [];

        // Compare user permissions
        if (isset($saved['snapshot']['user_permissions']) || isset($current['user_permissions'])) {
            $savedPerms = $saved['snapshot']['user_permissions'] ?? [];
            $currentPerms = $current['user_permissions'] ?? [];

            $diff = $this->compareUserPermissions($savedPerms, $currentPerms);

            if ([] !== $diff) {
                $differences['user_permissions'] = $diff;
            }
        }

        // Compare permission matrix
        if (isset($saved['snapshot']['permission_matrix']) || isset($current['permission_matrix'])) {
            $savedMatrix = $saved['snapshot']['permission_matrix'] ?? [];
            $currentMatrix = $current['permission_matrix'] ?? [];

            $diff = $this->compareMatrix($savedMatrix, $currentMatrix);

            if ([] !== $diff) {
                $differences['permission_matrix'] = $diff;
            }
        }

        // Compare object relationships
        if (isset($saved['snapshot']['object_relationships']) || isset($current['object_relationships'])) {
            $savedRels = $saved['snapshot']['object_relationships'] ?? [];
            $currentRels = $current['object_relationships'] ?? [];

            $diff = $this->compareObjectRelationships($savedRels, $currentRels);

            if ([] !== $diff) {
                $differences['object_relationships'] = $diff;
            }
        }

        return $differences;
    }

    /**
     * Delete a snapshot.
     *
     * @param string $name
     */
    public function deleteSnapshot(string $name): bool
    {
        $snapshotFile = $this->getSnapshotPath($name);

        if (File::exists($snapshotFile)) {
            return File::delete($snapshotFile);
        }

        return false;
    }

    /**
     * Generate a diff report between two snapshots.
     *
     * @param string $snapshot1
     * @param string $snapshot2
     */
    public function generateDiffReport(string $snapshot1, string $snapshot2): string
    {
        $snap1 = $this->loadSnapshot($snapshot1);
        $snap2 = $this->loadSnapshot($snapshot2);

        $differences = $this->compareSnapshots($snap1, $snap2);

        if ([] === $differences) {
            return sprintf("No differences found between '%s' and '%s'", $snapshot1, $snapshot2);
        }

        $report = "Differences between '{$snapshot1}' and '{$snapshot2}':\n\n";

        return $report . $this->formatDifferences($differences);
    }

    /**
     * Get all available snapshots.
     */
    public function listSnapshots(): Collection
    {
        return collect(File::files($this->snapshotPath))
            ->filter(static fn ($file): bool => 'json' === $file->getExtension())
            ->map(static fn ($file): array => [
                'name' => $file->getFilenameWithoutExtension(),
                'path' => $file->getPathname(),
                'size' => $file->getSize(),
                'modified' => $file->getMTime(),
            ])
            ->sortByDesc('modified');
    }

    /**
     * Load a saved snapshot.
     *
     * @param string $name
     */
    public function loadSnapshot(string $name): array
    {
        $snapshotFile = $this->getSnapshotPath($name);

        if (! File::exists($snapshotFile)) {
            throw new InvalidArgumentException('Snapshot not found: ' . $name);
        }

        return json_decode(File::get($snapshotFile), true);
    }

    /**
     * Save the current snapshot.
     *
     * @param string $name
     */
    public function saveSnapshot(string $name): void
    {
        $snapshotFile = $this->getSnapshotPath($name);

        $data = [
            'name' => $name,
            'timestamp' => now()->toIso8601String(),
            'snapshot' => $this->currentSnapshot,
        ];

        File::put($snapshotFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Enable snapshot updates for this instance.
     *
     * @param bool $update
     */
    public function updateSnapshots(bool $update = true): self
    {
        $this->updateSnapshots = $update;

        return $this;
    }

    private function compareMatrix(array $saved, array $current): array
    {
        $differences = [];

        $allKeys = array_unique(array_merge(array_keys($saved), array_keys($current)));

        foreach ($allKeys as $allKey) {
            if (! isset($saved[$allKey]) && isset($current[$allKey])) {
                $differences['added'][$allKey] = $current[$allKey];
            } elseif (isset($saved[$allKey]) && ! isset($current[$allKey])) {
                $differences['removed'][$allKey] = $saved[$allKey];
            } elseif (isset($saved[$allKey], $current[$allKey]) && $saved[$allKey] !== $current[$allKey]) {
                $differences['changed'][$allKey] = [
                    'from' => $saved[$allKey],
                    'to' => $current[$allKey],
                ];
            }
        }

        return $differences;
    }

    private function compareObjectRelationships(array $saved, array $current): array
    {
        $differences = [];

        $allObjects = array_unique(array_merge(array_keys($saved), array_keys($current)));

        foreach ($allObjects as $allObject) {
            if (! isset($saved[$allObject])) {
                $differences['new_objects'][] = $allObject;
            } elseif (! isset($current[$allObject])) {
                $differences['removed_objects'][] = $allObject;
            } else {
                // Compare relationships for this object
                $savedRels = $saved[$allObject];
                $currentRels = $current[$allObject];

                foreach ($savedRels as $relation => $users) {
                    if (! isset($currentRels[$relation])) {
                        $differences['objects'][$allObject]['removed_relations'][] = $relation;
                    } elseif ($users !== $currentRels[$relation]) {
                        $differences['objects'][$allObject]['changed_relations'][$relation] = [
                            'from' => $users,
                            'to' => $currentRels[$relation],
                        ];
                    }
                }

                foreach ($currentRels as $relation => $users) {
                    if (! isset($savedRels[$relation])) {
                        $differences['objects'][$allObject]['new_relations'][] = $relation;
                    }
                }
            }
        }

        return $differences;
    }

    private function compareUserPermissions(array $saved, array $current): array
    {
        $differences = [];

        // Check for missing users
        $savedUsers = array_keys($saved);
        $currentUsers = array_keys($current);

        $missingUsers = array_diff($savedUsers, $currentUsers);
        $newUsers = array_diff($currentUsers, $savedUsers);

        if ([] !== $missingUsers) {
            $differences['missing_users'] = $missingUsers;
        }

        if ([] !== $newUsers) {
            $differences['new_users'] = $newUsers;
        }

        // Compare permissions for common users
        foreach (array_intersect($savedUsers, $currentUsers) as $user) {
            $savedPerms = collect($saved[$user])->keyBy(static fn ($perm): string => sprintf('%s:%s', $perm['relation'], $perm['object']));

            $currentPerms = collect($current[$user])->keyBy(static fn ($perm): string => sprintf('%s:%s', $perm['relation'], $perm['object']));

            $changed = [];

            foreach ($savedPerms as $key => $savedPerm) {
                if (! isset($currentPerms[$key])) {
                    $changed[] = [
                        'type' => 'removed',
                        'permission' => $savedPerm,
                    ];
                } elseif ($savedPerm['allowed'] !== $currentPerms[$key]['allowed']) {
                    $changed[] = [
                        'type' => 'changed',
                        'from' => $savedPerm,
                        'to' => $currentPerms[$key],
                    ];
                }
            }

            foreach ($currentPerms as $key => $currentPerm) {
                if (! isset($savedPerms[$key])) {
                    $changed[] = [
                        'type' => 'added',
                        'permission' => $currentPerm,
                    ];
                }
            }

            if ([] !== $changed) {
                $differences['users'][$user] = $changed;
            }
        }

        return $differences;
    }

    private function ensureSnapshotDirectoryExists(): void
    {
        if (! File::exists($this->snapshotPath)) {
            File::makeDirectory($this->snapshotPath, 0o755, true);
        }
    }

    private function formatCategoryDifferences(string $category, array $diffs): string
    {
        $lines = [];

        switch ($category) {
            case 'user_permissions':
                if (isset($diffs['missing_users'])) {
                    $lines[] = 'Missing users: ' . implode(', ', $diffs['missing_users']);
                }

                if (isset($diffs['new_users'])) {
                    $lines[] = 'New users: ' . implode(', ', $diffs['new_users']);
                }

                if (isset($diffs['users'])) {
                    foreach ($diffs['users'] as $user => $changes) {
                        $lines[] = '
User: ' . $user;

                        foreach ($changes as $change) {
                            $lines[] = $this->formatPermissionChange($change);
                        }
                    }
                }

                break;

            case 'permission_matrix':
                foreach (['added', 'removed', 'changed'] as $type) {
                    if (isset($diffs[$type])) {
                        $lines[] = "\n" . ucfirst($type) . ':';

                        foreach ($diffs[$type] as $key => $value) {
                            if ('changed' === $type) {
                                $lines[] = sprintf('  %s: %s → %s', $key, $value['from'], $value['to']);
                            } else {
                                $lines[] = sprintf('  %s: ', $key) . ($value ? 'allowed' : 'denied');
                            }
                        }
                    }
                }

                break;

            default:
                $lines[] = json_encode($diffs, JSON_PRETTY_PRINT);
        }

        return implode("\n", $lines);
    }

    private function formatDifferences(array $differences): string
    {
        $output = [];

        foreach ($differences as $category => $diffs) {
            $output[] = strtoupper(str_replace('_', ' ', $category)) . ':';
            $output[] = str_repeat('-', 50);
            $output[] = $this->formatCategoryDifferences($category, $diffs);
            $output[] = '';
        }

        return implode("\n", $output);
    }

    private function formatPermissionChange(array $change): string
    {
        switch ($change['type']) {
            case 'added':
                $perm = $change['permission'];

                return sprintf('  + %s on %s: ', $perm['relation'], $perm['object']) .
                       ($perm['allowed'] ? 'allowed' : 'denied');

            case 'removed':
                $perm = $change['permission'];

                return sprintf('  - %s on %s: ', $perm['relation'], $perm['object']) .
                       ($perm['allowed'] ? 'allowed' : 'denied');

            case 'changed':
                return sprintf('  ~ %s on %s: ', $change['from']['relation'], $change['from']['object']) .
                       ($change['from']['allowed'] ? 'allowed' : 'denied') . ' → ' .
                       ($change['to']['allowed'] ? 'allowed' : 'denied');

            default:
                return '  ? Unknown change type: ' . $change['type'];
        }
    }

    private function getSnapshotPath(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);

        return $this->snapshotPath . '/' . $sanitized . '.json';
    }

    private function serializeTree($tree): array
    {
        // Serialize the expansion tree to a format that can be compared
        // This is a simplified version - implement based on actual tree structure
        return (array) $tree;
    }
}
