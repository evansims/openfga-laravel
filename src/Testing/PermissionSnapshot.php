<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use OpenFGA\Laravel\Facades\OpenFga;
use RuntimeException;

use function is_array;
use function is_object;
use function is_scalar;
use function is_string;
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

    /**
     * @var array{
     *   inheritance_trees?: array<string, array<string, mixed>>,
     *   permission_matrix?: array<string, bool>,
     *   object_relationships?: array<string, array<string, mixed>>,
     *   user_accessible_objects?: array<string, array<string, array<string, mixed>>>,
     *   user_permissions?: array<string, array<int, array{user: string, relation: string, object: string, allowed: bool}>>
     * }
     */
    private array $currentSnapshot = [];

    private bool $updateSnapshots = false;

    public function __construct(?string $snapshotPath = null)
    {
        $this->snapshotPath = $snapshotPath ?? storage_path('testing/permission-snapshots');
        $this->updateSnapshots = (bool) env('UPDATE_PERMISSION_SNAPSHOTS', false);

        $this->ensureSnapshotDirectoryExists();
    }

    /**
     * Assert that current permissions match the saved snapshot.
     *
     * @param string $name
     *
     * @throws RuntimeException
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

        if (! is_array($savedSnapshot)) {
            throw new RuntimeException('Invalid snapshot format: ' . $name);
        }

        /** @var array<string, mixed> $savedSnapshot */
        $differences = $this->compareSnapshots($savedSnapshot, $this->currentSnapshot);

        if ([] !== $differences) {
            $message = "Permission snapshot '{$name}' does not match:\n";
            $message .= $this->formatDifferences($differences);
            $message .= "\n\nTo update snapshots, run tests with UPDATE_PERMISSION_SNAPSHOTS=true";

            throw new RuntimeException($message);
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
            $tree = OpenFga::expand($object, '');

            if (! isset($this->currentSnapshot['inheritance_trees'])) {
                $this->currentSnapshot['inheritance_trees'] = [];
            }

            if (! isset($this->currentSnapshot['inheritance_trees'][$user])) {
                $this->currentSnapshot['inheritance_trees'][$user] = [];
            }

            $this->currentSnapshot['inheritance_trees'][$user][$object] = $this->serializeTree($tree);
        } catch (Exception) {
            if (! isset($this->currentSnapshot['inheritance_trees'])) {
                $this->currentSnapshot['inheritance_trees'] = [];
            }

            if (! isset($this->currentSnapshot['inheritance_trees'][$user])) {
                $this->currentSnapshot['inheritance_trees'][$user] = [];
            }

            $this->currentSnapshot['inheritance_trees'][$user][$object] = null;
        }

        return $this;
    }

    /**
     * Capture all permissions for a set of users and objects.
     *
     * @param array<int|string, string> $users
     * @param array<int|string, string> $objects
     * @param array<int|string, string> $relations
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
     * @param string                    $object
     * @param array<int|string, string> $relations
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

        if (! isset($this->currentSnapshot['object_relationships'])) {
            $this->currentSnapshot['object_relationships'] = [];
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

            if (! isset($this->currentSnapshot['user_accessible_objects'])) {
                $this->currentSnapshot['user_accessible_objects'] = [];
            }

            if (! isset($this->currentSnapshot['user_accessible_objects'][$userId])) {
                $this->currentSnapshot['user_accessible_objects'][$userId] = [];
            }

            if (! isset($this->currentSnapshot['user_accessible_objects'][$userId][$relation])) {
                $this->currentSnapshot['user_accessible_objects'][$userId][$relation] = [];
            }

            $this->currentSnapshot['user_accessible_objects'][$userId][$relation][$objectType] = $objects;
        } catch (Exception) {
            // If listing objects is not supported, skip
            if (! isset($this->currentSnapshot['user_accessible_objects'])) {
                $this->currentSnapshot['user_accessible_objects'] = [];
            }

            if (! isset($this->currentSnapshot['user_accessible_objects'][$userId])) {
                $this->currentSnapshot['user_accessible_objects'][$userId] = [];
            }

            if (! isset($this->currentSnapshot['user_accessible_objects'][$userId][$relation])) {
                $this->currentSnapshot['user_accessible_objects'][$userId][$relation] = [];
            }

            $this->currentSnapshot['user_accessible_objects'][$userId][$relation][$objectType] = null;
        }

        return $this;
    }

    /**
     * Capture permissions for a specific user.
     *
     * @param string                    $userId
     * @param array<int|string, string> $objects
     * @param array<int|string, string> $relations
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

        if (! isset($this->currentSnapshot['user_permissions'])) {
            $this->currentSnapshot['user_permissions'] = [];
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
     * @param  array<string, mixed> $saved
     * @param  array<string, mixed> $current
     * @return array<string, mixed>
     */
    public function compareSnapshots(array $saved, array $current): array
    {
        $differences = [];

        // Compare user permissions
        $savedSnapshot = isset($saved['snapshot']) && is_array($saved['snapshot']) ? $saved['snapshot'] : [];

        if (isset($savedSnapshot['user_permissions']) || isset($current['user_permissions'])) {
            /** @var array<string, mixed> $savedPerms */
            $savedPerms = isset($savedSnapshot['user_permissions']) && is_array($savedSnapshot['user_permissions']) ? $savedSnapshot['user_permissions'] : [];

            /** @var array<string, mixed> $currentPerms */
            $currentPerms = (isset($current['user_permissions']) && is_array($current['user_permissions'])) ? $current['user_permissions'] : [];

            $diff = $this->compareUserPermissions($savedPerms, $currentPerms);

            if ([] !== $diff) {
                $differences['user_permissions'] = $diff;
            }
        }

        // Compare permission matrix
        if (isset($savedSnapshot['permission_matrix']) || isset($current['permission_matrix'])) {
            /** @var array<string, mixed> $savedMatrix */
            $savedMatrix = isset($savedSnapshot['permission_matrix']) && is_array($savedSnapshot['permission_matrix']) ? $savedSnapshot['permission_matrix'] : [];

            /** @var array<string, mixed> $currentMatrix */
            $currentMatrix = (isset($current['permission_matrix']) && is_array($current['permission_matrix'])) ? $current['permission_matrix'] : [];

            $diff = $this->compareMatrix($savedMatrix, $currentMatrix);

            if ([] !== $diff) {
                $differences['permission_matrix'] = $diff;
            }
        }

        // Compare object relationships
        if (isset($savedSnapshot['object_relationships']) || isset($current['object_relationships'])) {
            /** @var array<string, mixed> $savedRels */
            $savedRels = isset($savedSnapshot['object_relationships']) && is_array($savedSnapshot['object_relationships']) ? $savedSnapshot['object_relationships'] : [];

            /** @var array<string, mixed> $currentRels */
            $currentRels = (isset($current['object_relationships']) && is_array($current['object_relationships'])) ? $current['object_relationships'] : [];

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
     *
     * @throws InvalidArgumentException|RuntimeException
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
     *
     * @return Collection<int, array{name: string, path: string, size: int, modified: int}>
     */
    public function listSnapshots(): Collection
    {
        return collect(File::files($this->snapshotPath))
            ->filter(static fn ($file): bool => 'json' === $file->getExtension())
            ->map(static fn ($file): array => [
                'name' => $file->getFilenameWithoutExtension(),
                'path' => $file->getPathname(),
                'size' => (int) $file->getSize(),
                'modified' => (int) $file->getMTime(),
            ])
            ->sortByDesc('modified')
            ->values();
    }

    /**
     * Load a saved snapshot.
     *
     * @param string $name
     *
     * @throws InvalidArgumentException|RuntimeException
     *
     * @return array<string, mixed>
     */
    public function loadSnapshot(string $name): array
    {
        $snapshotFile = $this->getSnapshotPath($name);

        if (! File::exists($snapshotFile)) {
            throw new InvalidArgumentException('Snapshot not found: ' . $name);
        }

        $data = json_decode(File::get($snapshotFile), true);

        if (! is_array($data)) {
            throw new RuntimeException('Invalid snapshot data format');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Save the current snapshot.
     *
     * @param string $name
     *
     * @throws RuntimeException
     */
    public function saveSnapshot(string $name): void
    {
        $snapshotFile = $this->getSnapshotPath($name);

        $data = [
            'name' => $name,
            'timestamp' => now()->toIso8601String(),
            'snapshot' => $this->currentSnapshot,
        ];

        $encoded = json_encode($data, JSON_PRETTY_PRINT);

        if (false === $encoded) {
            throw new RuntimeException('Failed to encode snapshot data');
        }
        File::put($snapshotFile, $encoded);
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

    /**
     * @param  array<string, mixed>                                                                                                        $saved
     * @param  array<string, mixed>                                                                                                        $current
     * @return array{added?: array<string, mixed>, removed?: array<string, mixed>, changed?: array<string, array{from: mixed, to: mixed}>}
     */
    private function compareMatrix(array $saved, array $current): array
    {
        /** @var array<string, mixed> $added */
        $added = [];

        /** @var array<string, mixed> $removed */
        $removed = [];

        /** @var array<string, array{from: mixed, to: mixed}> $changed */
        $changed = [];

        /** @var array<string> $allKeys */
        $allKeys = array_unique(array_merge(array_keys($saved), array_keys($current)));

        foreach ($allKeys as $allKey) {
            if (! isset($saved[$allKey]) && isset($current[$allKey])) {
                /** @var mixed $currentValue */
                $currentValue = $current[$allKey];

                /** @var array<string, mixed> $newAdded */
                $newAdded = array_merge($added, [$allKey => $currentValue]);
                $added = $newAdded;
            } elseif (isset($saved[$allKey]) && ! isset($current[$allKey])) {
                /** @var mixed $savedValue */
                $savedValue = $saved[$allKey];

                /** @var array<string, mixed> $newRemoved */
                $newRemoved = array_merge($removed, [$allKey => $savedValue]);
                $removed = $newRemoved;
            } elseif (isset($saved[$allKey], $current[$allKey]) && $saved[$allKey] !== $current[$allKey]) {
                /** @var mixed $savedValue */
                $savedValue = $saved[$allKey];

                /** @var mixed $currentValue */
                $currentValue = $current[$allKey];

                $changed[$allKey] = [
                    'from' => $savedValue,
                    'to' => $currentValue,
                ];
            }
        }

        /** @var array{added?: array<string, mixed>, removed?: array<string, mixed>, changed?: array<string, array{from: mixed, to: mixed}>} $differences */
        $differences = [];

        if ([] !== $added) {
            $differences['added'] = $added;
        }

        if ([] !== $removed) {
            $differences['removed'] = $removed;
        }

        if ([] !== $changed) {
            $differences['changed'] = $changed;
        }

        return $differences;
    }

    /**
     * @param  array<string, mixed> $saved
     * @param  array<string, mixed> $current
     * @return array<string, mixed>
     */
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
                /** @var mixed $savedRels */
                $savedRels = $saved[$allObject];

                /** @var mixed $currentRels */
                $currentRels = $current[$allObject];

                if (is_array($savedRels) && is_array($currentRels)) {
                    /** @var array<string, mixed> $savedRels */
                    /** @var mixed $users */
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

                    foreach (array_keys($currentRels) as $relation) {
                        if (! isset($savedRels[$relation])) {
                            $differences['objects'][$allObject]['new_relations'][] = $relation;
                        }
                    }
                }
            }
        }

        return $differences;
    }

    /**
     * @param  array<string, mixed> $saved
     * @param  array<string, mixed> $current
     * @return array<string, mixed>
     */
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
            /** @var array<array{user: string, relation: string, object: string, allowed: bool}> $userSavedPerms */
            $userSavedPerms = is_array($saved[$user]) ? $saved[$user] : [];
            $savedPerms = collect($userSavedPerms)->keyBy(static fn (array $perm): string => sprintf('%s:%s', $perm['relation'], $perm['object']));

            /** @var array<array{user: string, relation: string, object: string, allowed: bool}> $userCurrentPerms */
            $userCurrentPerms = is_array($current[$user]) ? $current[$user] : [];
            $currentPerms = collect($userCurrentPerms)->keyBy(static fn (array $perm): string => sprintf('%s:%s', $perm['relation'], $perm['object']));

            $changed = [];

            foreach ($savedPerms as $key => $savedPerm) {
                if (! isset($currentPerms[$key])) {
                    $changed[] = [
                        'type' => 'removed',
                        'permission' => $savedPerm,
                    ];
                } elseif (isset($currentPerms[$key]) && is_array($currentPerms[$key]) && isset($savedPerm['allowed'], $currentPerms[$key]['allowed']) && $savedPerm['allowed'] !== $currentPerms[$key]['allowed']) {
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

    /**
     * @param string               $category
     * @param array<string, mixed> $diffs
     */
    private function formatCategoryDifferences(string $category, array $diffs): string
    {
        $lines = [];

        switch ($category) {
            case 'user_permissions':
                if (isset($diffs['missing_users']) && is_array($diffs['missing_users'])) {
                    $missingUsers = array_map(
                        static fn ($v): string => is_scalar($v) || (is_object($v) && method_exists($v, '__toString')) ? (string) $v : '',
                        $diffs['missing_users'],
                    );
                    $lines[] = 'Missing users: ' . implode(', ', array_filter($missingUsers, static fn (string $s): bool => '' !== $s));
                }

                if (isset($diffs['new_users']) && is_array($diffs['new_users'])) {
                    $newUsers = array_map(
                        static fn ($v): string => is_scalar($v) || (is_object($v) && method_exists($v, '__toString')) ? (string) $v : '',
                        $diffs['new_users'],
                    );
                    $lines[] = 'New users: ' . implode(', ', array_filter($newUsers, static fn (string $s): bool => '' !== $s));
                }

                if (isset($diffs['users']) && is_array($diffs['users'])) {
                    /** @var array<string, mixed> $users */
                    $users = $diffs['users'];

                    /** @var mixed $changes */
                    foreach ($users as $user => $changes) {
                        $lines[] = '
User: ' . $user;

                        if (is_array($changes)) {
                            /** @var array<int, mixed> $changesArray */
                            $changesArray = $changes;

                            /** @var mixed $changeArray */
                            foreach ($changesArray as $changeArray) {
                                if (is_array($changeArray)) {
                                    /** @var array<string, mixed> $changeArrayTyped */
                                    $changeArrayTyped = $changeArray;
                                    $lines[] = $this->formatPermissionChange($changeArrayTyped);
                                }
                            }
                        }
                    }
                }

                break;

            case 'permission_matrix':
                foreach (['added', 'removed', 'changed'] as $type) {
                    if (isset($diffs[$type]) && is_array($diffs[$type])) {
                        $lines[] = "\n" . ucfirst($type) . ':';

                        /** @var array<string, mixed> $typeData */
                        $typeData = $diffs[$type];

                        /** @var mixed $value */
                        foreach ($typeData as $key => $value) {
                            if ('changed' === $type && is_array($value) && isset($value['from'], $value['to'])) {
                                $lines[] = sprintf('  %s: %s → %s', $key, (bool) $value['from'] ? 'allowed' : 'denied', (bool) $value['to'] ? 'allowed' : 'denied');
                            } else {
                                $lines[] = sprintf('  %s: ', $key) . ((bool) $value ? 'allowed' : 'denied');
                            }
                        }
                    }
                }

                break;

            default:
                $encoded = json_encode($diffs, JSON_PRETTY_PRINT);

                if (false !== $encoded) {
                    $lines[] = $encoded;
                }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $differences
     */
    private function formatDifferences(array $differences): string
    {
        $output = [];

        /** @var mixed $diffs */
        foreach ($differences as $category => $diffs) {
            $output[] = strtoupper(str_replace('_', ' ', $category)) . ':';
            $output[] = str_repeat('-', 50);

            /** @var array<string, mixed> $diffsArray */
            $diffsArray = is_array($diffs) ? $diffs : [];
            $output[] = $this->formatCategoryDifferences($category, $diffsArray);
            $output[] = '';
        }

        return implode("\n", $output);
    }

    /**
     * @param array<string, mixed> $change
     */
    private function formatPermissionChange(array $change): string
    {
        $type = isset($change['type']) && is_string($change['type']) ? $change['type'] : 'unknown';

        switch ($type) {
            case 'added':
                $perm = isset($change['permission']) && is_array($change['permission']) ? $change['permission'] : [];

                $relation = '';
                $object = '';
                $allowed = false;

                if (isset($perm['relation']) && (is_string($perm['relation']) || is_numeric($perm['relation']))) {
                    $relation = (string) $perm['relation'];
                }

                if (isset($perm['object']) && (is_string($perm['object']) || is_numeric($perm['object']))) {
                    $object = (string) $perm['object'];
                }

                if (isset($perm['allowed'])) {
                    $allowed = (bool) $perm['allowed'];
                }

                return sprintf('  + %s on %s: ', $relation, $object) . ($allowed ? 'allowed' : 'denied');

            case 'removed':
                $perm = isset($change['permission']) && is_array($change['permission']) ? $change['permission'] : [];

                $relation = '';
                $object = '';
                $allowed = false;

                if (isset($perm['relation']) && (is_string($perm['relation']) || is_numeric($perm['relation']))) {
                    $relation = (string) $perm['relation'];
                }

                if (isset($perm['object']) && (is_string($perm['object']) || is_numeric($perm['object']))) {
                    $object = (string) $perm['object'];
                }

                if (isset($perm['allowed'])) {
                    $allowed = (bool) $perm['allowed'];
                }

                return sprintf('  - %s on %s: ', $relation, $object) . ($allowed ? 'allowed' : 'denied');

            case 'changed':
                $fromRelation = '';
                $fromObject = '';
                $fromAllowed = false;
                $toAllowed = false;

                if (isset($change['from']) && is_array($change['from'])) {
                    if (isset($change['from']['relation']) && (is_string($change['from']['relation']) || is_numeric($change['from']['relation']))) {
                        $fromRelation = (string) $change['from']['relation'];
                    }

                    if (isset($change['from']['object']) && (is_string($change['from']['object']) || is_numeric($change['from']['object']))) {
                        $fromObject = (string) $change['from']['object'];
                    }

                    if (isset($change['from']['allowed'])) {
                        $fromAllowed = (bool) $change['from']['allowed'];
                    }
                }

                if (isset($change['to']) && is_array($change['to']) && isset($change['to']['allowed'])) {
                    $toAllowed = (bool) $change['to']['allowed'];
                }

                return sprintf('  ~ %s on %s: ', $fromRelation, $fromObject) .
                       ($fromAllowed ? 'allowed' : 'denied') . ' → ' .
                       ($toAllowed ? 'allowed' : 'denied');

            default:
                return '  ? Unknown change type: ' . $type;
        }
    }

    private function getSnapshotPath(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);

        if (null === $sanitized) {
            $sanitized = 'snapshot_' . time();
        }

        return $this->snapshotPath . '/' . $sanitized . '.json';
    }

    /**
     * @param  mixed                $tree
     * @return array<string, mixed>
     */
    private function serializeTree($tree): array
    {
        // Serialize the expansion tree to a format that can be compared
        // This is a simplified version - implement based on actual tree structure
        if (is_array($tree)) {
            /** @var array<string, mixed> $tree */
            return $tree;
        }

        if (is_object($tree)) {
            /** @var array<string, mixed> */
            return (array) $tree;
        }

        return [];
    }
}
