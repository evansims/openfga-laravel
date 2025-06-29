<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Testing;

use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Testing\{FakeOpenFga, FakesOpenFga};
use OpenFGA\Laravel\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

uses(TestCase::class, FakesOpenFga::class);

describe('FakesOpenFga', function (): void {
    it('fake manager connection method returns self', function (): void {
        $this->fakeOpenFga();
        $manager = $this->app->get(OpenFgaManager::class);

        $result = $manager->connection('test');

        expect($result)->toBe($manager);
    });

    it('fake manager delegates batch check method', function (): void {
        $fake = $this->fakeOpenFga();
        $manager = $this->app->get(OpenFgaManager::class);

        $fake->grant('user:123', 'viewer', 'document:456');

        $result = $manager->batchCheck([
            ['user' => 'user:123', 'relation' => 'viewer', 'object' => 'document:456'],
            ['user' => 'user:123', 'relation' => 'editor', 'object' => 'document:456'],
        ]);

        expect($result)->toBe([
            'user:123:viewer:document:456' => true,
            'user:123:editor:document:456' => false,
        ]);
    });

    it('fake manager delegates check method', function (): void {
        $this->fakeOpenFga();
        $manager = $this->app->get(OpenFgaManager::class);

        $manager->check('user:123', 'viewer', 'document:456');

        $this->assertPermissionChecked('user:123', 'viewer', 'document:456');
    });

    it('fake manager delegates expand method', function (): void {
        $fake = $this->fakeOpenFga();
        $manager = $this->app->get(OpenFgaManager::class);

        // Set up the permissions first
        $fake->grant('user:123', 'viewer', 'document:456');
        $fake->grant('user:456', 'viewer', 'document:456');

        $result = $manager->expand('document:456', 'viewer');

        expect($result)->toHaveKey('tree');
        expect($result['tree']['root']['leaf']['users'])->toContain('user:123', 'user:456');
    });

    it('fake manager delegates grant method', function (): void {
        $this->fakeOpenFga();
        $manager = $this->app->get(OpenFgaManager::class);

        $manager->grant('user:123', 'viewer', 'document:456');

        $this->assertPermissionGranted('user:123', 'viewer', 'document:456');
    });

    it('fake manager delegates list objects method', function (): void {
        $fake = $this->fakeOpenFga();
        $manager = $this->app->get(OpenFgaManager::class);

        // Set up the permissions first
        $fake->grant('user:123', 'viewer', 'document:1');
        $fake->grant('user:123', 'viewer', 'document:2');

        $result = $manager->listObjects('user:123', 'viewer', 'document');

        expect($result)->toContain('document:1', 'document:2');
    });

    it('fake manager delegates list users method', function (): void {
        $fake = $this->fakeOpenFga();
        $manager = $this->app->get(OpenFgaManager::class);

        // Set up the permissions first
        $fake->grant('user:123', 'viewer', 'document:456');
        $fake->grant('user:789', 'viewer', 'document:456');

        $result = $manager->listUsers('document:456', 'viewer');

        expect($result)->toContain('user:123', 'user:789');
    });

    it('fake manager delegates revoke method', function (): void {
        $fake = $this->fakeOpenFga();
        $manager = $this->app->get(OpenFgaManager::class);

        // First grant a permission
        $manager->grant('user:123', 'viewer', 'document:456');

        // Verify it was granted
        $tuple = [
            'user' => 'user:123',
            'relation' => 'viewer',
            'object' => 'document:456',
        ];
        expect($fake->getTuples())->toContain($tuple);
        expect($tuple)->toBePermissionTuple();

        // Then revoke it
        $manager->revoke('user:123', 'viewer', 'document:456');

        // Verify it was removed
        expect($fake->getTuples())->not->toContain([
            'user' => 'user:123',
            'relation' => 'viewer',
            'object' => 'document:456',
        ]);
    });

    it('fake manager delegates write batch method', function (): void {
        $fake = $this->fakeOpenFga();
        $manager = $this->app->get(OpenFgaManager::class);

        $writes = [
            ['user' => 'user:123', 'relation' => 'viewer', 'object' => 'document:456'],
        ];
        $deletes = [
            ['user' => 'user:789', 'relation' => 'editor', 'object' => 'document:456'],
        ];

        $manager->writeBatch($writes, $deletes);

        expect($fake->getWrites())->toHaveCount(1);
        expect($fake->getWrites()[0]['writes'])->toBe($writes);
        expect($fake->getWrites()[0]['deletes'])->toBe($deletes);
    });

    it('fake manager query builder delegates to fake', function (): void {
        $fake = $this->fakeOpenFga();
        $manager = $this->app->get(OpenFgaManager::class);

        // The fake doesn't have a deny method, we just don't grant the permission

        $result = $manager->query()
            ->for('user:999')
            ->can('admin')
            ->on('document:999')
            ->check();

        expect($result)->toBeFalse();
        $this->assertPermissionChecked('user:999', 'admin', 'document:999');
    });

    it('fake manager query method returns query builder', function (): void {
        $fake = $this->fakeOpenFga();
        $manager = $this->app->get(OpenFgaManager::class);

        $fake->grant('user:123', 'viewer', 'document:456');

        $result = $manager->query()
            ->for('user:123')
            ->can('viewer')
            ->on('document:456')
            ->check();

        expect($result)->toBeTrue();
        $this->assertPermissionChecked('user:123', 'viewer', 'document:456');
    });

    it('asserts no permission checks', function (): void {
        $this->fakeOpenFga();

        // Should pass when no checks performed
        $this->assertNoPermissionChecks();

        // Should fail when checks performed
        $this->fakeOpenFga->check('user:123', 'viewer', 'document:456');

        expect(fn () => $this->assertNoPermissionChecks())
            ->toThrow(AssertionFailedError::class);
    });

    it('asserts permission check count', function (): void {
        $fake = $this->fakeOpenFga();

        $fake->check('user:123', 'viewer', 'document:456');
        $fake->check('user:123', 'editor', 'document:456');

        // Should pass with correct count
        $this->assertPermissionCheckCount(2);

        // Should fail with incorrect count
        expect(fn () => $this->assertPermissionCheckCount(1))
            ->toThrow(AssertionFailedError::class);
    });

    it('asserts permission checked', function (): void {
        $fake = $this->fakeOpenFga();

        $fake->check('user:123', 'viewer', 'document:456');

        // Should pass when check was performed
        $this->assertPermissionChecked('user:123', 'viewer', 'document:456');

        // Should fail when check was not performed
        expect(fn () => $this->assertPermissionChecked('user:999', 'admin', 'document:999'))
            ->toThrow(AssertionFailedError::class);
    });

    it('asserts permission granted', function (): void {
        $fake = $this->fakeOpenFga();

        $fake->grant('user:123', 'viewer', 'document:456');

        // Should pass when permission was granted
        $this->assertPermissionGranted('user:123', 'viewer', 'document:456');

        // Should fail when permission was not granted
        expect(fn () => $this->assertPermissionGranted('user:999', 'admin', 'document:999'))
            ->toThrow(AssertionFailedError::class);
    });

    it('asserts permission not checked', function (): void {
        $fake = $this->fakeOpenFga();

        $fake->check('user:123', 'viewer', 'document:456');

        // Should pass when check was not performed
        $this->assertPermissionNotChecked('user:999', 'admin', 'document:999');

        // Should fail when check was performed
        expect(fn () => $this->assertPermissionNotChecked('user:123', 'viewer', 'document:456'))
            ->toThrow(AssertionFailedError::class);
    });

    it('asserts permission not granted', function (): void {
        $fake = $this->fakeOpenFga();

        $fake->grant('user:123', 'viewer', 'document:456');

        // Should pass when permission was not granted
        $this->assertPermissionNotGranted('user:999', 'admin', 'document:999');

        // Should fail when permission was granted
        expect(fn () => $this->assertPermissionNotGranted('user:123', 'viewer', 'document:456'))
            ->toThrow(AssertionFailedError::class);
    });

    it('fails assertions when fake not active', function (): void {
        // Don't call fakeOpenFga()

        expect(fn () => $this->assertNoPermissionChecks())
            ->toThrow(AssertionFailedError::class, 'OpenFGA fake is not active');

        expect(fn () => $this->assertPermissionCheckCount(0))
            ->toThrow(AssertionFailedError::class, 'OpenFGA fake is not active');

        expect(fn () => $this->assertPermissionChecked('user:123', 'viewer', 'document:456'))
            ->toThrow(AssertionFailedError::class, 'OpenFGA fake is not active');

        expect(fn () => $this->assertPermissionNotChecked('user:123', 'viewer', 'document:456'))
            ->toThrow(AssertionFailedError::class, 'OpenFGA fake is not active');

        expect(fn () => $this->assertPermissionGranted('user:123', 'viewer', 'document:456'))
            ->toThrow(AssertionFailedError::class, 'OpenFGA fake is not active');

        expect(fn () => $this->assertPermissionNotGranted('user:123', 'viewer', 'document:456'))
            ->toThrow(AssertionFailedError::class, 'OpenFGA fake is not active');
    });

    it('provides fake openfga instance', function (): void {
        $fake = $this->fakeOpenFga();

        expect($fake)->toBeInstanceOf(FakeOpenFga::class);
        expect($this->getFakeOpenFga())->toBe($fake);
    });

    it('replaces openfga manager in container', function (): void {
        $this->fakeOpenFga();

        $manager = $this->app->get(OpenFgaManager::class);
        expect($manager)->not->toBeInstanceOf(OpenFgaManager::class);

        $aliasedManager = $this->app->get('openfga.manager');
        expect($aliasedManager)->toBe($manager);
    });

    it('supports custom assertion messages', function (): void {
        $fake = $this->fakeOpenFga();

        expect(fn () => $this->assertPermissionCheckCount(5, 'Expected 5 checks'))
            ->toThrow(AssertionFailedError::class, 'Expected 5 checks');

        expect(fn () => $this->assertPermissionChecked('user:999', 'admin', 'document:999', 'Admin check missing'))
            ->toThrow(AssertionFailedError::class, 'Admin check missing');
    });
});
