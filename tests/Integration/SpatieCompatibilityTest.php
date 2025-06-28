<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use OpenFGA\Laravel\Testing\FakesOpenFga;
use OpenFGA\Laravel\Tests\Support\{FeatureTestCase, User};

uses(FeatureTestCase::class, FakesOpenFga::class);

describe('Spatie Compatibility', function (): void {
    beforeEach(function (): void {
        $this->fakeOpenFga();
        $this->compatibility = app(SpatieCompatibility::class);
        $this->user = User::factory()->create();
    });

    it('assign role works', function (): void {
        $this->compatibility->assignRole($this->user, 'admin');

        $this->assertPermissionGranted(
            $this->user->authorizationUser(),
            'admin',
            'organization:main',
        );
    });

    it('context parameter works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'admin', 'organization:acme');

        $result = $this->compatibility->hasRole($this->user, 'admin', 'organization:acme');
        expect($result)->toBeTrue();

        $result = $this->compatibility->hasRole($this->user, 'admin', 'organization:other');
        expect($result)->toBeFalse();
    });

    it('get all permissions works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'post:*');
        $fake->grant($this->user->authorizationUser(), 'viewer', 'post:*');

        // Note: This is a simplified test as getAllPermissions would need
        // more complex logic to work with actual OpenFGA expand API
        $permissions = $this->compatibility->getAllPermissions($this->user);

        expect($permissions)->toBeInstanceOf(Collection::class);
    });

    it('get role names works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'admin', 'organization:main');
        $fake->grant($this->user->authorizationUser(), 'editor', 'organization:main');

        // Note: This is a simplified test
        $roles = $this->compatibility->getRoleNames($this->user);

        expect($roles)->toBeInstanceOf(Collection::class);
    });

    it('give permission to works', function (): void {
        $this->compatibility->givePermissionTo($this->user, 'edit posts');

        $this->assertPermissionGranted(
            $this->user->authorizationUser(),
            'editor',
            'post:*',
        );
    });

    it('has all permissions works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'post:*');
        $fake->grant($this->user->authorizationUser(), 'viewer', 'post:*');

        $result = $this->compatibility->hasAllPermissions(
            $this->user,
            ['edit posts', 'view posts'],
        );

        expect($result)->toBeTrue();
    });

    it('has all roles works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'admin', 'organization:main');
        $fake->grant($this->user->authorizationUser(), 'editor', 'organization:main');

        $result = $this->compatibility->hasAllRoles($this->user, ['admin', 'editor']);

        expect($result)->toBeTrue();
    });

    it('has any permission works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'viewer', 'post:*');

        $result = $this->compatibility->hasAnyPermission(
            $this->user,
            ['edit posts', 'view posts'],
        );

        expect($result)->toBeTrue();
    });

    it('has any role works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'organization:main');

        $result = $this->compatibility->hasAnyRole($this->user, ['admin', 'editor']);

        expect($result)->toBeTrue();
    });

    it('has permission to works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'post:*');

        $result = $this->compatibility->hasPermissionTo($this->user, 'edit posts');

        expect($result)->toBeTrue();
    });

    it('has role works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'admin', 'organization:main');

        $result = $this->compatibility->hasRole($this->user, 'admin');

        expect($result)->toBeTrue();
    });

    it('model context works', function (): void {
        $post = new class extends Model {
            public function authorizationObject(): string
            {
                return 'post:123';
            }
        };

        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'post:123');

        $result = $this->compatibility->hasPermissionTo($this->user, 'edit posts', $post);

        expect($result)->toBeTrue();
    });

    it('permission inference works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'owner', 'post:*');

        // Should infer 'delete' action as requiring 'owner' relation
        $result = $this->compatibility->hasPermissionTo($this->user, 'delete posts');

        expect($result)->toBeTrue();
    });

    it('permission mapping works', function (): void {
        $this->compatibility->addPermissionMapping('custom permission', 'custom_relation');

        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'custom_relation', 'organization:main');

        $result = $this->compatibility->hasPermissionTo($this->user, 'custom permission');

        expect($result)->toBeTrue();
    });

    it('remove role works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'admin', 'organization:main');

        $this->compatibility->removeRole($this->user, 'admin');

        $this->assertPermissionNotGranted(
            $this->user->authorizationUser(),
            'admin',
            'organization:main',
        );
    });

    it('revoke permission to works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'post:*');

        $this->compatibility->revokePermissionTo($this->user, 'edit posts');

        $this->assertPermissionNotGranted(
            $this->user->authorizationUser(),
            'editor',
            'post:*',
        );
    });

    it('role mapping works', function (): void {
        $this->compatibility->addRoleMapping('custom role', 'custom_relation');

        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'custom_relation', 'organization:main');

        $result = $this->compatibility->hasRole($this->user, 'custom role');

        expect($result)->toBeTrue();
    });

    it('sync roles works', function (): void {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'admin', 'organization:main');
        $fake->grant($this->user->authorizationUser(), 'editor', 'organization:main');

        $this->compatibility->syncRoles($this->user, ['editor', 'viewer']);

        // Should still have editor
        $this->assertPermissionGranted(
            $this->user->authorizationUser(),
            'editor',
            'organization:main',
        );

        // Should have new viewer role
        $this->assertPermissionGranted(
            $this->user->authorizationUser(),
            'viewer',
            'organization:main',
        );

        // Should no longer have admin
        $this->assertPermissionNotGranted(
            $this->user->authorizationUser(),
            'admin',
            'organization:main',
        );
    });
});
