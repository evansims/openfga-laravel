<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\App;
use OpenFGA\Laravel\Database\PermissionMigration;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

// Test implementation of PermissionMigration
final class PermissionMigrationTest extends PermissionMigration
{
    public array $appliedPermissions = [];

    public array $appliedRollback = [];

    public function __construct(?OpenFgaManager $manager = null)
    {
        parent::__construct($manager);
    }

    protected function applyPermissions(): void
    {
        $this->appliedPermissions = $this->permissions;
    }

    protected function applyRollback(): void
    {
        $this->appliedRollback = $this->rollbackPermissions;
    }

    protected function definePermissions(): void
    {
        $this->grant('user:1', 'owner', 'document:123');
        $this->grant('user:2', 'editor', 'document:123');
        $this->grantToMany(['user:3', 'user:4'], 'viewer', 'document:123');
    }
}

describe('PermissionMigration', function (): void {
    beforeEach(function (): void {
        // Use the test app container which has config service registered
        $this->container = $this->app;

        $this->config = [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'url' => 'http://localhost:8080',
                    'store_id' => 'test-store',
                    'model_id' => 'test-model',
                    'credentials' => [
                        'method' => 'none',
                    ],
                ],
                'secondary' => [
                    'url' => 'http://localhost:8081',
                    'store_id' => 'test-store-2',
                    'model_id' => 'test-model-2',
                    'credentials' => [
                        'method' => 'none',
                    ],
                ],
            ],
            'cache' => [
                'read_through' => false,
                'tags' => ['enabled' => false],
            ],
        ];

        $this->manager = new OpenFgaManager(
            container: $this->container,
            config: $this->config,
        );
        $this->container->instance(OpenFgaManager::class, $this->manager);

        $this->migration = new PermissionMigrationTest($this->manager);
    });

    describe('Permission Definition', function (): void {
        it('defines single permissions', function (): void {
            $migration = new class($this->manager) extends PermissionMigration {
                public array $testPermissions = [];

                protected function applyPermissions(): void
                {
                }

                protected function definePermissions(): void
                {
                    $this->grant('user:test', 'admin', 'org:acme');
                    $this->testPermissions = $this->permissions;
                }
            };

            $migration->up();

            expect($migration->testPermissions)->toHaveCount(1);
            expect($migration->testPermissions[0])->toBe([
                'user' => 'user:test',
                'relation' => 'admin',
                'object' => 'org:acme',
            ]);
        });

        it('defines multiple permissions', function (): void {
            $migration = new class($this->manager) extends PermissionMigration {
                public array $testPermissions = [];

                protected function applyPermissions(): void
                {
                }

                protected function definePermissions(): void
                {
                    $this->grantMany([
                        ['user' => 'user:1', 'relation' => 'owner', 'object' => 'doc:1'],
                        ['user' => 'user:2', 'relation' => 'editor', 'object' => 'doc:2'],
                    ]);
                    $this->testPermissions = $this->permissions;
                }
            };

            $migration->up();

            expect($migration->testPermissions)->toHaveCount(2);
        });

        it('grants permissions to multiple users', function (): void {
            $migration = new class($this->manager) extends PermissionMigration {
                public array $testPermissions = [];

                protected function applyPermissions(): void
                {
                }

                protected function definePermissions(): void
                {
                    $this->grantToMany(['user:1', 'user:2', 'user:3'], 'viewer', 'doc:shared');
                    $this->testPermissions = $this->permissions;
                }
            };

            $migration->up();

            expect($migration->testPermissions)->toHaveCount(3);
            expect($migration->testPermissions[0]['relation'])->toBe('viewer');
            expect($migration->testPermissions[0]['object'])->toBe('doc:shared');
        });
    });

    describe('Migration Execution', function (): void {
        it('applies permissions on up', function (): void {
            $this->migration->up();

            expect($this->migration->appliedPermissions)->toHaveCount(4);
            expect($this->migration->appliedPermissions[0])->toBe([
                'user' => 'user:1',
                'relation' => 'owner',
                'object' => 'document:123',
            ]);
        });

        it('applies rollback on down', function (): void {
            $this->migration->up();
            $this->migration->down();

            expect($this->migration->appliedRollback)->toHaveCount(4);
            expect($this->migration->appliedRollback)->toBe($this->migration->appliedPermissions);
        });

        it('defines custom rollback permissions', function (): void {
            $migration = new class($this->manager) extends PermissionMigration {
                public array $testRollback = [];

                protected function applyPermissions(): void
                {
                }

                protected function applyRollback(): void
                {
                }

                protected function definePermissions(): void
                {
                    $this->grant('user:new', 'admin', 'org:test');
                }

                protected function defineRollbackPermissions(): void
                {
                    parent::defineRollbackPermissions();
                    $this->revokeOnRollback('user:old', 'admin', 'org:test');
                    $this->testRollback = $this->rollbackPermissions;
                }
            };

            $migration->up();
            $migration->down();

            expect($migration->testRollback)->toHaveCount(2);
        });
    });

    describe('Connection Support', function (): void {
        it('uses specific connections', function (): void {
            $usedConnection = null;

            $migration = new class($this->manager) extends PermissionMigration {
                public $usedConnection = null;

                protected function applyPermissions(): void
                {
                }

                protected function definePermissions(): void
                {
                    $this->usingConnection('secondary', function ($connection): void {
                        $this->usedConnection = 'secondary';
                    });
                }
            };

            $migration->up();

            expect($migration->usedConnection)->toBe('secondary');
        });
    });
});
