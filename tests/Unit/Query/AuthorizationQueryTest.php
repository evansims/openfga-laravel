<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit;

use InvalidArgumentException;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Query\AuthorizationQuery;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('AuthorizationQuery', function (): void {
    beforeEach(function (): void {
        // Create a mock manager using the interface
        $this->manager = $this->createMock(ManagerInterface::class);

        // Since AuthorizationQuery needs OpenFgaManager, not the interface,
        // we need to create a real instance with test config
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
            ],
            'cache' => [
                'read_through' => false,
                'tags' => ['enabled' => false],
            ],
        ];

        $this->manager = new OpenFgaManager(container: $this->container, config: $this->config);
    });

    describe('Query Building', function (): void {
        it('creates a query builder instance', function (): void {
            $query = $this->manager->query();

            expect($query)->toBeInstanceOf(AuthorizationQuery::class);
        });

        it('supports fluent interface for building queries', function (): void {
            $query = $this->manager->query()
                ->for('user:123')
                ->can('read')
                ->on('document:456');

            expect($query)->toBeInstanceOf(AuthorizationQuery::class);
        });

        it('supports method aliases', function (): void {
            $query = $this->manager->query()
                ->user('user:123')
                ->relation('read')
                ->object('document:456');

            expect($query)->toBeInstanceOf(AuthorizationQuery::class);
        });

        it('supports contextual tuples', function (): void {
            $query = $this->manager->query()
                ->withContext([
                    ['user' => 'user:123', 'relation' => 'member', 'object' => 'team:abc'],
                ])
                ->withTuple('user:456', 'admin', 'org:xyz');

            expect($query)->toBeInstanceOf(AuthorizationQuery::class);
        });

        it('supports user type filters', function (): void {
            $query = $this->manager->query()
                ->whereUserType(['user', 'service'])
                ->whereUserType('group');

            expect($query)->toBeInstanceOf(AuthorizationQuery::class);
        });

        it('supports relation filters', function (): void {
            $query = $this->manager->query()
                ->whereRelation(['read', 'write'])
                ->whereRelation('admin');

            expect($query)->toBeInstanceOf(AuthorizationQuery::class);
        });
    });

    describe('Check Operations', function (): void {
        it('validates required fields for check', function (): void {
            expect(fn () => $this->manager->query()->check())
                ->toThrow(InvalidArgumentException::class, 'User is required for check query');

            expect(fn () => $this->manager->query()->for('user:123')->check())
                ->toThrow(InvalidArgumentException::class, 'Relation is required for check query');

            expect(fn () => $this->manager->query()->for('user:123')->can('read')->check())
                ->toThrow(InvalidArgumentException::class, 'Object is required for check query');
        });

        it('supports batch checks with defaults', function (): void {
            $query = $this->manager->query()
                ->for('user:123')
                ->can('read');

            $checks = [
                ['object' => 'document:1'],
                ['object' => 'document:2'],
                ['object' => 'document:3', 'relation' => 'write'],
            ];

            // This would need mocking to test properly
            expect($query)->toBeInstanceOf(AuthorizationQuery::class);
        });
    });

    describe('List Operations', function (): void {
        it('validates required fields for listObjects', function (): void {
            expect(fn () => $this->manager->query()->listObjects())
                ->toThrow(InvalidArgumentException::class, 'User is required for listObjects query');

            expect(fn () => $this->manager->query()->for('user:123')->listObjects())
                ->toThrow(InvalidArgumentException::class, 'Relation is required for listObjects query');

            expect(fn () => $this->manager->query()->for('user:123')->can('read')->listObjects())
                ->toThrow(InvalidArgumentException::class, 'Type is required for listObjects query');
        });

        it('validates required fields for listUsers', function (): void {
            expect(fn () => $this->manager->query()->listUsers())
                ->toThrow(InvalidArgumentException::class, 'Object is required for listUsers query');

            expect(fn () => $this->manager->query()->on('document:123')->listUsers())
                ->toThrow(InvalidArgumentException::class, 'Relation is required for listUsers query');
        });

        it('validates required fields for listRelations', function (): void {
            expect(fn () => $this->manager->query()->listRelations())
                ->toThrow(InvalidArgumentException::class, 'User is required for listRelations query');

            expect(fn () => $this->manager->query()->for('user:123')->listRelations())
                ->toThrow(InvalidArgumentException::class, 'Object is required for listRelations query');
        });
    });

    describe('Write Operations', function (): void {
        it('validates required fields for grant', function (): void {
            expect(fn () => $this->manager->query()->grant())
                ->toThrow(InvalidArgumentException::class, 'User is required for write query');

            expect(fn () => $this->manager->query()->for('user:123')->grant())
                ->toThrow(InvalidArgumentException::class, 'Relation is required for write query');

            expect(fn () => $this->manager->query()->for('user:123')->can('read')->grant())
                ->toThrow(InvalidArgumentException::class, 'Object is required for write query');
        });

        it('supports batch grants', function (): void {
            $query = $this->manager->query()
                ->can('read')
                ->on('document:123');

            $grants = [
                ['user' => 'user:1'],
                ['user' => 'user:2'],
                ['user' => 'user:3', 'relation' => 'write'],
            ];

            // This would need mocking to test properly
            expect($query)->toBeInstanceOf(AuthorizationQuery::class);
        });

        it('validates required fields for revoke', function (): void {
            expect(fn () => $this->manager->query()->revoke())
                ->toThrow(InvalidArgumentException::class, 'User is required for write query');

            expect(fn () => $this->manager->query()->for('user:123')->revoke())
                ->toThrow(InvalidArgumentException::class, 'Relation is required for write query');

            expect(fn () => $this->manager->query()->for('user:123')->can('read')->revoke())
                ->toThrow(InvalidArgumentException::class, 'Object is required for write query');
        });
    });

    describe('Query Cloning', function (): void {
        it('can create a fresh query instance', function (): void {
            $query1 = $this->manager->query()
                ->for('user:123')
                ->can('read')
                ->on('document:456');

            $query2 = $query1->fresh();

            expect($query2)->toBeInstanceOf(AuthorizationQuery::class);
            expect($query2)->not->toBe($query1);
        });
    });
});
