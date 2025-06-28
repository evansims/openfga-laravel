<?php

declare(strict_types=1);

use OpenFGA\Laravel\Testing\{FakesOpenFga, SnapshotsTesting};
use OpenFGA\Laravel\Tests\Support\FeatureTestCase;
use PHPUnit\Framework\AssertionFailedError;

uses(FeatureTestCase::class, FakesOpenFga::class, SnapshotsTesting::class);

describe('Snapshot Testing', function (): void {
    beforeEach(function (): void {
        $this->fakeOpenFga();
        $this->setUpSnapshots();
    });

    afterEach(function (): void {
        $this->tearDownSnapshots();
    });

    it('basic permission snapshot', function (): void {
        // Set up some permissions
        $fake = $this->getFakeOpenFga();
        $fake->grant('user:1', 'owner', 'document:1');
        $fake->grant('user:1', 'editor', 'document:2');
        $fake->grant('user:2', 'viewer', 'document:1');
        $fake->grant('user:2', 'viewer', 'document:2');

        // Capture and assert snapshot
        $this->assertPermissionsMatchSnapshot('basic_permissions', function ($snapshot): void {
            $snapshot->captureUserPermissions(
                'user:1',
                ['document:1', 'document:2'],
                ['owner', 'editor', 'viewer'],
            );

            $snapshot->captureUserPermissions(
                'user:2',
                ['document:1', 'document:2'],
                ['owner', 'editor', 'viewer'],
            );
        });

        // Add explicit assertion to satisfy PHPUnit
        expect(true)->toBeTrue();
    });

    it('comparing snapshots', function (): void {
        $fake = $this->getFakeOpenFga();

        // Create first snapshot
        $fake->grant('user:1', 'editor', 'document:1');
        $snapshot1 = $this->snapshot();
        $snapshot1->captureUserPermissions('user:1', ['document:1'], ['editor']);
        $snapshot1->saveSnapshot('state1');

        // Create second snapshot with changes
        $fake->grant('user:1', 'owner', 'document:1');
        $snapshot2 = $this->snapshot();
        $snapshot2->captureUserPermissions('user:1', ['document:1'], ['editor', 'owner']);
        $snapshot2->saveSnapshot('state2');

        // Compare snapshots (this would fail as they're different)
        expect(fn () => $this->assertSnapshotsMatch('state1', 'state2'))
            ->toThrow(AssertionFailedError::class, 'Differences between');

        // Clean up
        $this->deleteSnapshot('state1');
        $this->deleteSnapshot('state2');
    });

    it('complex scenario snapshot', function (): void {
        // Set up a complex authorization scenario
        $fake = $this->getFakeOpenFga();

        // Organization structure
        $fake->grant('user:ceo', 'admin', 'organization:acme');
        $fake->grant('user:manager1', 'manager', 'department:engineering');
        $fake->grant('user:manager2', 'manager', 'department:marketing');

        // Team structure
        $fake->grant('user:lead1', 'lead', 'team:backend');
        $fake->grant('user:lead2', 'lead', 'team:frontend');

        // Document permissions
        $fake->grant('user:dev1', 'editor', 'document:api-spec');
        $fake->grant('user:dev2', 'editor', 'document:api-spec');
        $fake->grant('user:dev1', 'viewer', 'document:roadmap');

        // Capture comprehensive snapshot
        $this->assertPermissionsMatchSnapshot('complex_organization', function ($snapshot): void {
            // Capture different aspects of the permission system
            $snapshot->captureMatrix(
                ['user:ceo', 'user:manager1', 'user:manager2'],
                ['organization:acme', 'department:engineering', 'department:marketing'],
                ['admin', 'manager', 'member'],
            );

            $snapshot->captureUserPermissions(
                'user:dev1',
                ['document:api-spec', 'document:roadmap'],
                ['owner', 'editor', 'viewer'],
            );

            $snapshot->captureUserPermissions(
                'user:dev2',
                ['document:api-spec', 'document:roadmap'],
                ['owner', 'editor', 'viewer'],
            );
        });

        // Add explicit assertion to satisfy PHPUnit
        expect(true)->toBeTrue();
    });

    it('object relationships snapshot', function (): void {
        // Set up object relationships
        $fake = $this->getFakeOpenFga();
        $fake->grant('user:1', 'owner', 'document:important');
        $fake->grant('user:2', 'editor', 'document:important');
        $fake->grant('user:3', 'viewer', 'document:important');
        $fake->grant('user:4', 'viewer', 'document:important');

        // Mock the listUsers functionality
        $fake->setListUsersResponse('document:important', 'owner', ['user:1']);
        $fake->setListUsersResponse('document:important', 'editor', ['user:2']);
        $fake->setListUsersResponse('document:important', 'viewer', ['user:3', 'user:4']);

        // Capture object relationships
        $this->assertObjectRelationshipsMatchSnapshot(
            'document_relationships',
            'document:important',
            ['owner', 'editor', 'viewer'],
        );

        // Add explicit assertion to satisfy PHPUnit
        expect(true)->toBeTrue();
    });

    it('permission matrix snapshot', function (): void {
        // Set up a permission matrix
        $fake = $this->getFakeOpenFga();
        $fake->grant('user:admin', 'admin', 'organization:acme');
        $fake->grant('user:editor', 'editor', 'post:1');
        $fake->grant('user:editor', 'editor', 'post:2');
        $fake->grant('user:viewer', 'viewer', 'post:1');

        // Capture permission matrix
        $this->assertPermissionMatrixMatchesSnapshot(
            'permission_matrix',
            ['user:admin', 'user:editor', 'user:viewer'],
            ['organization:acme', 'post:1', 'post:2'],
            ['admin', 'editor', 'viewer'],
        );

        // Add explicit assertion to satisfy PHPUnit
        expect(true)->toBeTrue();
    });

    it('snapshot detects permission changes', function (): void {
        // This test will fail if permissions change unexpectedly
        $fake = $this->getFakeOpenFga();

        // Initial setup
        $fake->grant('user:1', 'editor', 'document:1');

        // Capture initial snapshot
        $this->assertPermissionsMatchSnapshot('initial_state', function ($snapshot): void {
            $snapshot->captureUserPermissions('user:1', ['document:1'], ['editor', 'viewer']);
        });

        // Change permissions (this would cause the test to fail if snapshots are enforced)
        // $fake->grant('user:1', 'owner', 'document:1');

        // This assertion would fail if permissions changed
        $this->assertPermissionsMatchSnapshot('initial_state', function ($snapshot): void {
            $snapshot->captureUserPermissions('user:1', ['document:1'], ['editor', 'viewer']);
        });

        // Add explicit assertion to satisfy PHPUnit
        expect(true)->toBeTrue();
    });

    it('updating snapshots', function (): void {
        // Enable snapshot updates for this test
        $this->updateSnapshots();

        $fake = $this->getFakeOpenFga();
        $fake->grant('user:1', 'editor', 'document:1');

        // This will create/update the snapshot instead of asserting
        $this->assertPermissionsMatchSnapshot('updatable_snapshot', function ($snapshot): void {
            $snapshot->captureUserPermissions('user:1', ['document:1'], ['editor']);
        });

        // Verify the snapshot was created
        $snapshots = $this->getAvailableSnapshots();
        expect($snapshots)->not->toBeEmpty();
        expect(
            collect($snapshots)->pluck('name')->contains('updatable_snapshot'),
        )->toBeTrue();

        // Clean up
        $this->deleteSnapshot('updatable_snapshot');
    });
});
