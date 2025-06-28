<?php

declare(strict_types=1);

use OpenFGA\Laravel\Events\{BatchWriteCompleted, BatchWriteFailed, ObjectsListed, PermissionChecked, PermissionGranted, PermissionRevoked, RelationExpanded};

describe('Events', function (): void {
    describe('BatchWriteCompleted', function (): void {
        it('tracks batch write completion', function (): void {
            $writes = [
                ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
                ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
            ];

            $deletes = [
                ['user' => 'user:3', 'relation' => 'reader', 'object' => 'document:3'],
            ];

            $event = new BatchWriteCompleted($writes, $deletes, 'main', 0.150);

            expect(count($event->writes))->toBe(2);
            expect(count($event->deletes))->toBe(1);
            expect($event->getTotalOperations())->toBe(3);

            $summary = $event->getSummary();
            expect($summary['writes'])->toBe(2);
            expect($summary['deletes'])->toBe(1);
            expect($summary['total'])->toBe(3);
            expect($summary['duration'])->toBe(0.150);
            expect($summary['connection'])->toBe('main');
        });
    });

    describe('BatchWriteFailed', function (): void {
        it('tracks batch write failure', function (): void {
            $writes = [
                ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
            ];

            $exception = new RuntimeException('Connection failed');

            $event = new BatchWriteFailed($writes, [], 'main', $exception);

            expect($event->getTotalOperations())->toBe(1);
            expect($event->exception)->toBe($exception);

            $summary = $event->getSummary();
            expect($summary['writes'])->toBe(1);
            expect($summary['deletes'])->toBe(0);
            expect($summary['error'])->toBe('Connection failed');
            expect($summary['exception_class'])->toBe('RuntimeException');
        });
    });

    describe('ObjectsListed', function (): void {
        it('tracks object listing', function (): void {
            $objects = ['document:1', 'document:2', 'document:3'];

            $event = new ObjectsListed(
                'user:123',
                'reader',
                'document',
                $objects,
                'main',
                0.045,
            );

            expect($event->user)->toBe('user:123');
            expect($event->relation)->toBe('reader');
            expect($event->type)->toBe('document');
            expect($event->objects)->toBe($objects);
            expect($event->getObjectCount())->toBe(3);
        });
    });

    describe('PermissionChecked', function (): void {
        it('tracks permission check', function (): void {
            $event = new PermissionChecked(
                'user:123',
                'reader',
                'document:456',
                true,
                'main',
                0.025,
                false,
                ['ip' => '127.0.0.1'],
            );

            expect($event->user)->toBe('user:123');
            expect($event->relation)->toBe('reader');
            expect($event->object)->toBe('document:456');
            expect($event->allowed)->toBeTrue();
            expect($event->connection)->toBe('main');
            expect($event->duration)->toBe(0.025);
            expect($event->cached)->toBeFalse();
            expect($event->context)->toBe(['ip' => '127.0.0.1']);
            expect($event->toString())->toBe('user:123#reader@document:456 = allowed');
        });
    });

    describe('PermissionGranted', function (): void {
        it('tracks permission grant', function (): void {
            $event = new PermissionGranted(
                'user:123',
                'editor',
                'document:456',
                'main',
                0.030,
            );

            expect($event->user)->toBe('user:123');
            expect($event->relation)->toBe('editor');
            expect($event->object)->toBe('document:456');
            expect($event->toString())->toBe('Granted: user:123#editor@document:456');
        });
    });

    describe('PermissionRevoked', function (): void {
        it('tracks permission revocation', function (): void {
            $event = new PermissionRevoked(
                'user:123',
                'editor',
                'document:456',
                'main',
                0.035,
            );

            expect($event->user)->toBe('user:123');
            expect($event->relation)->toBe('editor');
            expect($event->object)->toBe('document:456');
            expect($event->toString())->toBe('Revoked: user:123#editor@document:456');
        });
    });

    describe('RelationExpanded', function (): void {
        it('tracks relation expansion', function (): void {
            $result = [
                'tree' => [
                    'root' => [
                        'name' => 'document:1#reader',
                        'leaf' => [
                            'users' => ['user:123', 'user:456'],
                        ],
                    ],
                ],
            ];

            $event = new RelationExpanded(
                'document:1',
                'reader',
                $result,
                'main',
                0.055,
            );

            expect($event->object)->toBe('document:1');
            expect($event->relation)->toBe('reader');
            expect($event->result)->toBe($result);

            $users = $event->getUsers();
            expect($users)->toHaveCount(2);
            expect($users)->toContain('user:123');
            expect($users)->toContain('user:456');
        });

        it('handles complex structure expansion', function (): void {
            $result = [
                'tree' => [
                    'root' => [
                        'name' => 'document:1#viewer',
                        'union' => [
                            'nodes' => [
                                [
                                    'leaf' => [
                                        'users' => ['user:1', 'user:2'],
                                    ],
                                ],
                                [
                                    'intersection' => [
                                        'nodes' => [
                                            [
                                                'leaf' => [
                                                    'users' => ['user:3', 'user:4'],
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

            $event = new RelationExpanded('document:1', 'viewer', $result);

            $users = $event->getUsers();
            expect($users)->toHaveCount(4);
            expect($users)->toContain('user:1');
            expect($users)->toContain('user:2');
            expect($users)->toContain('user:3');
            expect($users)->toContain('user:4');
        });
    });
});
