<?php

declare(strict_types=1);

use OpenFGA\Laravel\Events\{BatchWriteCompleted, BatchWriteFailed, ObjectsListed, PermissionChecked, PermissionGranted, PermissionRevoked, RelationExpanded};
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

// Import shared test helpers
use function OpenFGA\Laravel\Tests\Support\{createPermissionTuple, measurePerformance};

// Datasets for event testing
dataset('event_durations', [
    'fast' => 0.025,
    'normal' => 0.100,
    'slow' => 0.500,
]);

dataset('batch_operations', [
    'small batch' => [
        'writes' => [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
        ],
        'deletes' => [],
        'total' => 1,
    ],
    'mixed batch' => [
        'writes' => [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
        ],
        'deletes' => [
            ['user' => 'user:3', 'relation' => 'reader', 'object' => 'document:3'],
        ],
        'total' => 3,
    ],
    'large batch' => [
        'writes' => array_map(
            callback: fn ($i) => ['user' => "user:{$i}", 'relation' => 'viewer', 'object' => "doc:{$i}"],
            array: range(1, 10),
        ),
        'deletes' => array_map(
            callback: fn ($i) => ['user' => "user:{$i}", 'relation' => 'editor', 'object' => "doc:{$i}"],
            array: range(11, 15),
        ),
        'total' => 15,
    ],
]);

dataset('permission_contexts', [
    'no context' => [null],
    'with IP' => [['ip' => '127.0.0.1']],
    'with metadata' => [['ip' => '192.168.1.1', 'user_agent' => 'Test/1.0', 'request_id' => 'abc123']],
]);

// Custom expectations for events
expect()->extend('toBeOpenFgaEvent', function (): void {
    $this->toBeObject()
        ->toHaveProperty('connection')
        ->toHaveProperty('duration');
});

expect()->extend('toHaveEventSummary', function (array $expected): void {
    $summary = $this->value->getSummary();

    foreach ($expected as $key => $value) {
        expect($summary)->toHaveKey($key);
        expect($summary[$key])->toBe($value);
    }
});

describe('Events', function (): void {
    describe('BatchWriteCompleted', function (): void {
        it('tracks batch operations', function (array $writes, array $deletes, int $total): void {
            $event = new BatchWriteCompleted(
                writes: $writes,
                deletes: $deletes,
                connection: 'main',
                duration: 0.150,
            );

            expect($event)
                ->toBeOpenFgaEvent()
                ->writes->toHaveCount(count($writes))
                ->deletes->toHaveCount(count($deletes))
                ->getTotalOperations()->toBe($total);

            expect($event)->toHaveEventSummary([
                'writes' => count($writes),
                'deletes' => count($deletes),
                'total' => $total,
                'duration' => 0.150,
                'connection' => 'main',
            ]);
        })->with('batch_operations');

        it('handles various durations', function (float $duration): void {
            $event = new BatchWriteCompleted(
                writes: [['user' => 'user:1', 'relation' => 'viewer', 'object' => 'doc:1']],
                deletes: [],
                connection: 'main',
                duration: $duration,
            );

            expect($event)
                ->duration->toBe($duration)
                ->and($event->getSummary()['duration'])->toBe($duration);
        })->with('event_durations');
    });

    describe('BatchWriteFailed', function (): void {
        it('tracks batch failures', function (array $writes, array $deletes, int $total): void {
            $exception = new RuntimeException('Connection failed');
            $event = new BatchWriteFailed(
                writes: $writes,
                deletes: $deletes,
                connection: 'main',
                exception: $exception,
            );

            expect($event)
                ->toBeOpenFgaEvent()
                ->getTotalOperations()->toBe($total)
                ->exception->toBe($exception);

            expect($event)->toHaveEventSummary([
                'writes' => count($writes),
                'deletes' => count($deletes),
                'error' => 'Connection failed',
                'exception_class' => 'RuntimeException',
            ]);
        })->with('batch_operations');

        it('handles different exception types', function (): void {
            $exceptions = [
                new RuntimeException('Runtime error'),
                new InvalidArgumentException('Invalid argument'),
                new Exception('Generic error'),
            ];

            foreach ($exceptions as $exception) {
                $event = new BatchWriteFailed(
                    writes: [],
                    deletes: [],
                    connection: 'main',
                    exception: $exception,
                );

                expect($event->getSummary())
                    ->toHaveKey('exception_class', $exception::class)
                    ->toHaveKey('error', $exception->getMessage());
            }
        });
    });

    describe('ObjectsListed', function (): void {
        it('tracks object listing', function (): void {
            $objects = ['document:1', 'document:2', 'document:3'];

            $event = new ObjectsListed(
                user: 'user:123',
                relation: 'reader',
                type: 'document',
                objects: $objects,
                connection: 'main',
                duration: 0.045,
            );

            expect($event)
                ->toBeOpenFgaEvent()
                ->user->toBeOpenFgaIdentifier()
                ->relation->toBeOpenFgaRelation()
                ->type->toBeOpenFgaType()
                ->objects->toBeArray()
                ->getObjectCount()->toBe(3);
        });

        it('handles empty object lists', function (): void {
            $event = new ObjectsListed(
                user: 'user:123',
                relation: 'viewer',
                type: 'file',
                objects: [],
                connection: 'main',
                duration: 0.010,
            );

            expect($event)
                ->objects->toBeEmpty()
                ->getObjectCount()->toBe(0);
        });

        it('tracks large object lists efficiently', function (): void {
            $objects = array_map(callback: fn ($i) => "document:{$i}", array: range(start: 1, end: 1000));

            $result = measurePerformance(function () use ($objects): void {
                $event = new ObjectsListed(
                    user: 'user:123',
                    relation: 'viewer',
                    type: 'document',
                    objects: $objects,
                    connection: 'main',
                    duration: 0.100,
                );
                $event->getObjectCount();
            });
            expect($result['duration_ms'])->toBeLessThan(5);
        });
    });

    describe('PermissionChecked', function (): void {
        it('tracks permission checks with context', function (?array $context): void {
            $event = new PermissionChecked(
                user: 'user:123',
                relation: 'reader',
                object: 'document:456',
                allowed: true,
                connection: 'main',
                duration: 0.025,
                cached: false,
                context: $context ?? [],
            );

            expect($event)
                ->toBeOpenFgaEvent()
                ->user->toBeOpenFgaIdentifier()
                ->relation->toBeOpenFgaRelation()
                ->object->toBeOpenFgaIdentifier()
                ->allowed->toBeTrue()
                ->cached->toBeFalse()
                ->context->toBe($context ?? [])
                ->toString()->toBe('user:123#reader@document:456 = allowed');
        })->with('permission_contexts');

        it('differentiates allowed and denied permissions', function (): void {
            $allowed = new PermissionChecked(
                user: 'user:1',
                relation: 'viewer',
                object: 'doc:1',
                allowed: true,
                connection: 'main',
                duration: 0.01,
            );
            $denied = new PermissionChecked(
                user: 'user:2',
                relation: 'editor',
                object: 'doc:2',
                allowed: false,
                connection: 'main',
                duration: 0.02,
            );

            expect($allowed)
                ->allowed->toBeTrue()
                ->toString()->toContain('allowed')
                ->and($denied)
                ->allowed->toBeFalse()
                ->toString()->toContain('denied');
        });

        it('tracks cached results', function (): void {
            $cached = new PermissionChecked(
                user: 'user:1',
                relation: 'viewer',
                object: 'doc:1',
                allowed: true,
                connection: 'main',
                duration: 0.001,
                cached: true,
            );
            $fresh = new PermissionChecked(
                user: 'user:1',
                relation: 'viewer',
                object: 'doc:1',
                allowed: true,
                connection: 'main',
                duration: 0.025,
                cached: false,
            );

            expect($cached)
                ->cached->toBeTrue()
                ->duration->toBeLessThan($fresh->duration)
                ->and($fresh)
                ->cached->toBeFalse();
        });
    });

    describe('Permission mutations', function (): void {
        dataset('permission_mutations', [
            'grant viewer' => [PermissionGranted::class, 'viewer', 'Granted'],
            'grant editor' => [PermissionGranted::class, 'editor', 'Granted'],
            'grant owner' => [PermissionGranted::class, 'owner', 'Granted'],
            'revoke viewer' => [PermissionRevoked::class, 'viewer', 'Revoked'],
            'revoke editor' => [PermissionRevoked::class, 'editor', 'Revoked'],
            'revoke owner' => [PermissionRevoked::class, 'owner', 'Revoked'],
        ]);

        it('tracks permission mutations', function (string $eventClass, string $relation, string $prefix): void {
            $event = new $eventClass(
                user: 'user:123',
                relation: $relation,
                object: 'document:456',
                connection: 'main',
                duration: 0.030,
            );

            expect($event)
                ->toBeOpenFgaEvent()
                ->user->toBeOpenFgaIdentifier()
                ->relation->toBe($relation)
                ->object->toBeOpenFgaIdentifier()
                ->toString()->toBe("{$prefix}: user:123#{$relation}@document:456");
        })->with('permission_mutations');

        it('compares grant and revoke operations', function (): void {
            $grant = new PermissionGranted(
                user: 'user:1',
                relation: 'editor',
                object: 'doc:1',
                connection: 'main',
                duration: 0.025,
            );
            $revoke = new PermissionRevoked(
                user: 'user:1',
                relation: 'editor',
                object: 'doc:1',
                connection: 'main',
                duration: 0.030,
            );

            expect($grant->toString())
                ->toStartWith('Granted:')
                ->and($revoke->toString())
                ->toStartWith('Revoked:')
                ->and($grant->user)->toBe($revoke->user)
                ->and($grant->relation)->toBe($revoke->relation)
                ->and($grant->object)->toBe($revoke->object);
        });
    });

    describe('RelationExpanded', function (): void {
        dataset('expansion_structures', [
            'simple leaf' => [
                [
                    'tree' => [
                        'root' => [
                            'name' => 'document:1#reader',
                            'leaf' => [
                                'users' => ['user:123', 'user:456'],
                            ],
                        ],
                    ],
                ],
                ['user:123', 'user:456'],
            ],
            'union structure' => [
                [
                    'tree' => [
                        'root' => [
                            'name' => 'document:1#viewer',
                            'union' => [
                                'nodes' => [
                                    ['leaf' => ['users' => ['user:1', 'user:2']]],
                                    ['leaf' => ['users' => ['user:3']]],
                                ],
                            ],
                        ],
                    ],
                ],
                ['user:1', 'user:2', 'user:3'],
            ],
            'empty expansion' => [
                [
                    'tree' => [
                        'root' => [
                            'name' => 'document:1#admin',
                            'leaf' => ['users' => []],
                        ],
                    ],
                ],
                [],
            ],
        ]);

        it('tracks various expansion structures', function (array $result, array $expectedUsers): void {
            $event = new RelationExpanded(
                object: 'document:1',
                relation: 'reader',
                result: $result,
                connection: 'main',
                duration: 0.055,
            );

            expect($event)
                ->toBeOpenFgaEvent()
                ->object->toBeOpenFgaIdentifier()
                ->relation->toBeOpenFgaRelation()
                ->result->toBe($result)
                ->getUsers()->toBe($expectedUsers);
        })->with('expansion_structures');

        it('handles complex nested structures', function (): void {
            $result = [
                'tree' => [
                    'root' => [
                        'name' => 'document:1#viewer',
                        'union' => [
                            'nodes' => [
                                ['leaf' => ['users' => ['user:1', 'user:2']]],
                                [
                                    'intersection' => [
                                        'nodes' => [
                                            ['leaf' => ['users' => ['user:3', 'user:4']]],
                                            ['leaf' => ['users' => ['user:5']]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $event = new RelationExpanded(
                object: 'document:1',
                relation: 'viewer',
                result: $result,
            );

            expect($event->getUsers())
                ->toHaveCount(5)
                ->each->toBeOpenFgaIdentifier();
        });

        it('extracts users from deeply nested structures', function (): void {
            $result = [
                'tree' => [
                    'root' => [
                        'name' => 'document:1#viewer',
                        'union' => [
                            'nodes' => [
                                ['leaf' => ['users' => ['user:a']]],
                                [
                                    'union' => [
                                        'nodes' => [
                                            ['leaf' => ['users' => ['user:b', 'user:c']]],
                                            [
                                                'intersection' => [
                                                    'nodes' => [
                                                        ['leaf' => ['users' => ['user:d']]],
                                                        ['leaf' => ['users' => ['user:e', 'user:f']]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $event = new RelationExpanded(
                object: 'document:1',
                relation: 'viewer',
                result: $result,
            );
            $users = $event->getUsers();

            expect($users)
                ->toHaveCount(6)
                ->toContain('user:a', 'user:b', 'user:c', 'user:d', 'user:e', 'user:f');
        });
    });

    describe('Event serialization', function (): void {
        it('serializes events for logging', function (): void {
            $events = [
                new PermissionChecked(
                    user: 'user:1',
                    relation: 'viewer',
                    object: 'doc:1',
                    allowed: true,
                    connection: 'main',
                    duration: 0.025,
                ),
                new PermissionGranted(
                    user: 'user:2',
                    relation: 'editor',
                    object: 'doc:2',
                    connection: 'main',
                    duration: 0.030,
                ),
                new BatchWriteCompleted(
                    writes: [createPermissionTuple(user: 'user:3', relation: 'owner', object: 'doc:3')],
                    deletes: [],
                    connection: 'main',
                    duration: 0.040,
                ),
            ];

            foreach ($events as $event) {
                expect($event)
                    ->toBeObject();
                // ->toHaveMethod('getSummary');

                if (method_exists($event, 'toString')) {
                    expect($event->toString())->toBeString()->not->toBeEmpty();
                }
            }
        });
    });
});

// Mark event tests
pest()->group('events');
