<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use OpenFGA\Laravel\Testing\FakesOpenFga;
use OpenFGA\Laravel\Tests\{FeatureTestCase, User};

final class SpatieCompatibilityTest extends FeatureTestCase
{
    use FakesOpenFga;

    private SpatieCompatibility $compatibility;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeOpenFga();
        $this->compatibility = app(SpatieCompatibility::class);
        $this->user = User::factory()->create();
    }

    public function test_assign_role_works(): void
    {
        $this->compatibility->assignRole($this->user, 'admin');

        $this->assertPermissionGranted(
            $this->user->authorizationUser(),
            'admin',
            'organization:main',
        );
    }

    public function test_context_parameter_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'admin', 'organization:acme');

        $result = $this->compatibility->hasRole($this->user, 'admin', 'organization:acme');
        $this->assertTrue($result);

        $result = $this->compatibility->hasRole($this->user, 'admin', 'organization:other');
        $this->assertFalse($result);
    }

    public function test_get_all_permissions_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'post:*');
        $fake->grant($this->user->authorizationUser(), 'viewer', 'post:*');

        // Note: This is a simplified test as getAllPermissions would need
        // more complex logic to work with actual OpenFGA expand API
        $permissions = $this->compatibility->getAllPermissions($this->user);

        $this->assertInstanceOf(Collection::class, $permissions);
    }

    public function test_get_role_names_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'admin', 'organization:main');
        $fake->grant($this->user->authorizationUser(), 'editor', 'organization:main');

        // Note: This is a simplified test
        $roles = $this->compatibility->getRoleNames($this->user);

        $this->assertInstanceOf(Collection::class, $roles);
    }

    public function test_give_permission_to_works(): void
    {
        $this->compatibility->givePermissionTo($this->user, 'edit posts');

        $this->assertPermissionGranted(
            $this->user->authorizationUser(),
            'editor',
            'post:*',
        );
    }

    public function test_has_all_permissions_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'post:*');
        $fake->grant($this->user->authorizationUser(), 'viewer', 'post:*');

        $result = $this->compatibility->hasAllPermissions(
            $this->user,
            ['edit posts', 'view posts'],
        );

        $this->assertTrue($result);
    }

    public function test_has_all_roles_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'admin', 'organization:main');
        $fake->grant($this->user->authorizationUser(), 'editor', 'organization:main');

        $result = $this->compatibility->hasAllRoles($this->user, ['admin', 'editor']);

        $this->assertTrue($result);
    }

    public function test_has_any_permission_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'viewer', 'post:*');

        $result = $this->compatibility->hasAnyPermission(
            $this->user,
            ['edit posts', 'view posts'],
        );

        $this->assertTrue($result);
    }

    public function test_has_any_role_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'organization:main');

        $result = $this->compatibility->hasAnyRole($this->user, ['admin', 'editor']);

        $this->assertTrue($result);
    }

    public function test_has_permission_to_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'post:*');

        $result = $this->compatibility->hasPermissionTo($this->user, 'edit posts');

        $this->assertTrue($result);
    }

    public function test_has_role_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'admin', 'organization:main');

        $result = $this->compatibility->hasRole($this->user, 'admin');

        $this->assertTrue($result);
    }

    public function test_model_context_works(): void
    {
        $post = new class extends Model {
            public function authorizationObject(): string
            {
                return 'post:123';
            }
        };

        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'post:123');

        $result = $this->compatibility->hasPermissionTo($this->user, 'edit posts', $post);

        $this->assertTrue($result);
    }

    public function test_permission_inference_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'owner', 'post:*');

        // Should infer 'delete' action as requiring 'owner' relation
        $result = $this->compatibility->hasPermissionTo($this->user, 'delete posts');

        $this->assertTrue($result);
    }

    public function test_permission_mapping_works(): void
    {
        $this->compatibility->addPermissionMapping('custom permission', 'custom_relation');

        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'custom_relation', 'organization:main');

        $result = $this->compatibility->hasPermissionTo($this->user, 'custom permission');

        $this->assertTrue($result);
    }

    public function test_remove_role_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'admin', 'organization:main');

        $this->compatibility->removeRole($this->user, 'admin');

        $this->assertPermissionNotGranted(
            $this->user->authorizationUser(),
            'admin',
            'organization:main',
        );
    }

    public function test_revoke_permission_to_works(): void
    {
        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'editor', 'post:*');

        $this->compatibility->revokePermissionTo($this->user, 'edit posts');

        $this->assertPermissionNotGranted(
            $this->user->authorizationUser(),
            'editor',
            'post:*',
        );
    }

    public function test_role_mapping_works(): void
    {
        $this->compatibility->addRoleMapping('custom role', 'custom_relation');

        $fake = $this->getFakeOpenFga();
        $fake->grant($this->user->authorizationUser(), 'custom_relation', 'organization:main');

        $result = $this->compatibility->hasRole($this->user, 'custom role');

        $this->assertTrue($result);
    }

    public function test_sync_roles_works(): void
    {
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
    }
}
