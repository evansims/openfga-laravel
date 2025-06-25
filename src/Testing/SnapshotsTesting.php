<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Closure;

/**
 * Trait for permission snapshot testing.
 *
 * Provides convenient methods for capturing and asserting permission snapshots in tests.
 */
trait SnapshotsTesting
{
    protected ?PermissionSnapshot $permissionSnapshot = null;

    /**
     * Capture and assert object relationships snapshot.
     *
     * @param string $name
     * @param string $object
     * @param array  $relations
     */
    protected function assertObjectRelationshipsMatchSnapshot(
        string $name,
        string $object,
        array $relations,
    ): void {
        $this->assertPermissionsMatchSnapshot($name, static function (PermissionSnapshot $snapshot) use ($object, $relations): void {
            $snapshot->captureObjectRelationships($object, $relations);
        });
    }

    /**
     * Capture and assert a permission matrix snapshot.
     *
     * @param string $name
     * @param array  $users
     * @param array  $objects
     * @param array  $relations
     */
    protected function assertPermissionMatrixMatchesSnapshot(
        string $name,
        array $users,
        array $objects,
        array $relations,
    ): void {
        $this->assertPermissionsMatchSnapshot($name, static function (PermissionSnapshot $snapshot) use ($users, $objects, $relations): void {
            $snapshot->captureMatrix($users, $objects, $relations);
        });
    }

    /**
     * Assert permissions match a snapshot.
     *
     * @param string  $name
     * @param Closure $capture
     */
    protected function assertPermissionsMatchSnapshot(string $name, Closure $capture): void
    {
        $snapshot = $this->snapshot();

        // Execute the capture closure with the snapshot instance
        $capture($snapshot);

        // Assert it matches the saved snapshot
        $snapshot->assertMatchesSnapshot($name);
    }

    /**
     * Compare two named snapshots.
     *
     * @param string $snapshot1
     * @param string $snapshot2
     */
    protected function assertSnapshotsMatch(string $snapshot1, string $snapshot2): void
    {
        $snap = $this->snapshot();
        $report = $snap->generateDiffReport($snapshot1, $snapshot2);

        if (! str_contains((string) $report, 'No differences found')) {
            $this->fail($report);
        }
    }

    /**
     * Capture and assert user permissions snapshot.
     *
     * @param string $name
     * @param string $userId
     * @param array  $objects
     * @param array  $relations
     */
    protected function assertUserPermissionsMatchSnapshot(
        string $name,
        string $userId,
        array $objects,
        array $relations,
    ): void {
        $this->assertPermissionsMatchSnapshot($name, static function (PermissionSnapshot $snapshot) use ($userId, $objects, $relations): void {
            $snapshot->captureUserPermissions($userId, $objects, $relations);
        });
    }

    /**
     * Delete a snapshot by name.
     *
     * @param string $name
     */
    protected function deleteSnapshot(string $name): void
    {
        $this->snapshot()->deleteSnapshot($name);
    }

    /**
     * Get all available snapshots for this test.
     */
    protected function getAvailableSnapshots(): array
    {
        return $this->snapshot()->listSnapshots()->toArray();
    }

    /**
     * Get the snapshot storage path.
     */
    protected function getSnapshotPath(): string
    {
        $class = str_replace('\\', '/', static::class);

        return storage_path('testing/permission-snapshots/' . $class);
    }

    /**
     * Set up snapshot testing.
     */
    protected function setUpSnapshots(): void
    {
        $this->permissionSnapshot = new PermissionSnapshot($this->getSnapshotPath());
    }

    /**
     * Create a new permission snapshot instance.
     */
    protected function snapshot(): PermissionSnapshot
    {
        if (! $this->permissionSnapshot) {
            $this->permissionSnapshot = new PermissionSnapshot($this->getSnapshotPath());
        }

        return $this->permissionSnapshot->clear();
    }

    /**
     * Clean up snapshots after test.
     */
    protected function tearDownSnapshots(): void
    {
        $this->permissionSnapshot = null;
    }

    /**
     * Update snapshots for this test.
     */
    protected function updateSnapshots(): void
    {
        if ($this->permissionSnapshot) {
            $this->permissionSnapshot->updateSnapshots(true);
        }
    }
}
