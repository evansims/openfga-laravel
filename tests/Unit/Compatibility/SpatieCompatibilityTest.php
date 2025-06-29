<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use OpenFGA\Laravel\Contracts\{AuthorizationUser, ManagerInterface};
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('SpatieCompatibility', function (): void {
    beforeEach(function (): void {
        $this->mockManager = Mockery::mock(ManagerInterface::class);
        $this->app->instance(ManagerInterface::class, $this->mockManager);

        $this->compatibility = new SpatieCompatibility;
    });

    describe('mappings', function (): void {
        it('adds custom permission mapping', function (): void {
            $this->compatibility->addPermissionMapping('custom permission', 'custom_relation');

            $mappings = $this->compatibility->getPermissionMappings();

            expect($mappings)->toHaveKey('custom permission')
                ->and($mappings['custom permission'])->toBe('custom_relation');
        });

        it('adds custom role mapping', function (): void {
            $this->compatibility->addRoleMapping('custom_role', 'custom_relation');

            $mappings = $this->compatibility->getRoleMappings();

            expect($mappings)->toHaveKey('custom_role')
                ->and($mappings['custom_role'])->toBe('custom_relation');
        });

        it('maps permissions to relations', function (): void {
            $mappings = $this->compatibility->getPermissionMappings();

            expect($mappings)->toHaveKey('edit posts')
                ->and($mappings['edit posts'])->toBe('editor')
                ->and($mappings)->toHaveKey('view posts')
                ->and($mappings['view posts'])->toBe('viewer');
        });

        it('maps roles to relations', function (): void {
            $mappings = $this->compatibility->getRoleMappings();

            expect($mappings)->toHaveKey('admin')
                ->and($mappings['admin'])->toBe('admin')
                ->and($mappings)->toHaveKey('editor')
                ->and($mappings['editor'])->toBe('editor');
        });
    });

    describe('role assignment', function (): void {
        it('assigns role to authorization user', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('grant')
                ->once()
                ->with('user:123', 'admin', 'organization:main');

            $this->compatibility->assignRole($user, 'admin');
        });

        it('assigns role to regular model', function (): void {
            $user = new class extends Model {
                public function getKey(): mixed
                {
                    return 456;
                }
            };

            $this->mockManager->shouldReceive('grant')
                ->once()
                ->with('user:456', 'editor', 'organization:main');

            $this->compatibility->assignRole($user, 'editor');
        });

        it('assigns role with custom context', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('grant')
                ->once()
                ->with('user:123', 'admin', 'project:abc');

            $this->compatibility->assignRole($user, 'admin', 'project:abc');
        });

        it('removes role from user', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('revoke')
                ->once()
                ->with('user:123', 'admin', 'organization:main');

            $this->compatibility->removeRole($user, 'admin');
        });
    });

    describe('permission checks', function (): void {
        it('checks permission', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'editor', 'post:*')
                ->andReturn(true);

            $result = $this->compatibility->hasPermissionTo($user, 'edit posts');

            expect($result)->toBeTrue();
        });

        it('checks any permission', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'editor', 'post:*')
                ->andReturn(true);

            $result = $this->compatibility->hasAnyPermission($user, ['edit posts', 'delete posts']);

            expect($result)->toBeTrue();
        });

        it('checks any permission returns false when none match', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('check')
                ->twice()
                ->with('user:123', Mockery::any(), Mockery::any())
                ->andReturn(false);

            $result = $this->compatibility->hasAnyPermission($user, ['edit posts', 'delete posts']);

            expect($result)->toBeFalse();
        });

        it('checks all permissions', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('check')
                ->twice()
                ->with('user:123', Mockery::any(), Mockery::any())
                ->andReturn(true);

            $result = $this->compatibility->hasAllPermissions($user, ['edit posts', 'view posts']);

            expect($result)->toBeTrue();
        });

        it('checks all permissions returns false when missing', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'editor', 'post:*')
                ->andReturn(false);

            $result = $this->compatibility->hasAllPermissions($user, ['edit posts', 'view posts']);

            expect($result)->toBeFalse();
        });
    });

    describe('role checks', function (): void {
        it('checks role', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'admin', 'organization:main')
                ->andReturn(true);

            $result = $this->compatibility->hasRole($user, 'admin');

            expect($result)->toBeTrue();
        });

        it('checks role with context', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'editor', 'project:abc')
                ->andReturn(false);

            $result = $this->compatibility->hasRole($user, 'editor', 'project:abc');

            expect($result)->toBeFalse();
        });

        it('checks any role', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('check')
                ->once()
                ->with('user:123', 'admin', 'organization:main')
                ->andReturn(true);

            $result = $this->compatibility->hasAnyRole($user, ['admin', 'editor']);

            expect($result)->toBeTrue();
        });

        it('checks all roles', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('check')
                ->twice()
                ->with('user:123', Mockery::any(), 'organization:main')
                ->andReturn(true);

            $result = $this->compatibility->hasAllRoles($user, ['admin', 'editor']);

            expect($result)->toBeTrue();
        });
    });

    describe('permission management', function (): void {
        it('gives permission to user', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('grant')
                ->once()
                ->with('user:123', 'editor', 'post:*');

            $this->compatibility->givePermissionTo($user, 'edit posts');
        });

        it('gives permission with model context', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $model = new class extends Model {
                public function authorizationObject(): string
                {
                    return 'document:456';
                }
            };

            $this->mockManager->shouldReceive('grant')
                ->once()
                ->with('user:123', 'viewer', 'document:456');

            $this->compatibility->givePermissionTo($user, 'view posts', $model);
        });

        it('revokes permission from user', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            $this->mockManager->shouldReceive('revoke')
                ->once()
                ->with('user:123', 'editor', 'post:*');

            $this->compatibility->revokePermissionTo($user, 'edit posts');
        });
    });

    describe('permission retrieval', function (): void {
        it('gets all permissions', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            // Mock checks for all known permissions
            $this->mockManager->shouldReceive('check')
                ->atLeast(1)
                ->with('user:123', Mockery::any(), Mockery::any())
                ->andReturn(true, false, true, false, false, false, false, false);

            $permissions = $this->compatibility->getAllPermissions($user);

            expect($permissions)->toBeInstanceOf(Collection::class);
        });

        it('gets role names', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            // Mock checks for all known roles
            $this->mockManager->shouldReceive('check')
                ->atLeast(1)
                ->with('user:123', Mockery::any(), 'organization:main')
                ->andReturn(true, false, false, false, false);

            $roles = $this->compatibility->getRoleNames($user);

            expect($roles)->toBeInstanceOf(Collection::class);
        });
    });

    describe('sync operations', function (): void {
        it('syncs permissions', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            // Mock getting current permissions
            $this->mockManager->shouldReceive('check')
                ->atLeast(1)
                ->andReturn(false);

            // Mock granting new permissions
            $this->mockManager->shouldReceive('grant')
                ->twice()
                ->with('user:123', Mockery::any(), Mockery::any());

            $this->compatibility->syncPermissions($user, ['edit posts', 'view posts']);
        });

        it('syncs roles', function (): void {
            $user = new class extends Model implements AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'user:123';
                }
            };

            // Mock getting current roles
            $this->mockManager->shouldReceive('check')
                ->atLeast(1)
                ->andReturn(false);

            // Mock assigning new roles
            $this->mockManager->shouldReceive('grant')
                ->twice()
                ->with('user:123', Mockery::any(), 'organization:main');

            $this->compatibility->syncRoles($user, ['admin', 'editor']);
        });
    });

    describe('edge cases', function (): void {
        it('handles non scalar user keys', function (): void {
            $user = new class extends Model {
                public function getKey(): mixed
                {
                    return ['composite' => 'key'];
                }
            };

            $this->mockManager->shouldReceive('grant')
                ->once()
                ->with('user:', 'admin', 'organization:main');

            $this->compatibility->assignRole($user, 'admin');
        });
    });
});
