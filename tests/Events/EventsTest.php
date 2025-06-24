<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Events;

use OpenFGA\Laravel\Events\{BatchWriteCompleted, BatchWriteFailed, ObjectsListed, PermissionChecked, PermissionGranted, PermissionRevoked, RelationExpanded};
use OpenFGA\Laravel\Tests\TestCase;
use RuntimeException;

use function count;

final class EventsTest extends TestCase
{
    public function test_batch_write_completed_event(): void
    {
        $writes = [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
            ['user' => 'user:2', 'relation' => 'writer', 'object' => 'document:2'],
        ];

        $deletes = [
            ['user' => 'user:3', 'relation' => 'reader', 'object' => 'document:3'],
        ];

        $event = new BatchWriteCompleted($writes, $deletes, 'main', 0.150);

        $this->assertEquals(2, count($event->writes));
        $this->assertEquals(1, count($event->deletes));
        $this->assertEquals(3, $event->getTotalOperations());

        $summary = $event->getSummary();
        $this->assertEquals(2, $summary['writes']);
        $this->assertEquals(1, $summary['deletes']);
        $this->assertEquals(3, $summary['total']);
        $this->assertEquals(0.150, $summary['duration']);
        $this->assertEquals('main', $summary['connection']);
    }

    public function test_batch_write_failed_event(): void
    {
        $writes = [
            ['user' => 'user:1', 'relation' => 'reader', 'object' => 'document:1'],
        ];

        $exception = new RuntimeException('Connection failed');

        $event = new BatchWriteFailed($writes, [], 'main', $exception);

        $this->assertEquals(1, $event->getTotalOperations());
        $this->assertSame($exception, $event->exception);

        $summary = $event->getSummary();
        $this->assertEquals(1, $summary['writes']);
        $this->assertEquals(0, $summary['deletes']);
        $this->assertEquals('Connection failed', $summary['error']);
        $this->assertEquals('RuntimeException', $summary['exception_class']);
    }

    public function test_objects_listed_event(): void
    {
        $objects = ['document:1', 'document:2', 'document:3'];

        $event = new ObjectsListed(
            'user:123',
            'reader',
            'document',
            $objects,
            'main',
            0.045,
        );

        $this->assertEquals('user:123', $event->user);
        $this->assertEquals('reader', $event->relation);
        $this->assertEquals('document', $event->type);
        $this->assertEquals($objects, $event->objects);
        $this->assertEquals(3, $event->getObjectCount());
    }

    public function test_permission_checked_event(): void
    {
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

        $this->assertEquals('user:123', $event->user);
        $this->assertEquals('reader', $event->relation);
        $this->assertEquals('document:456', $event->object);
        $this->assertTrue($event->allowed);
        $this->assertEquals('main', $event->connection);
        $this->assertEquals(0.025, $event->duration);
        $this->assertFalse($event->cached);
        $this->assertEquals(['ip' => '127.0.0.1'], $event->context);
        $this->assertEquals('user:123#reader@document:456 = allowed', $event->toString());
    }

    public function test_permission_granted_event(): void
    {
        $event = new PermissionGranted(
            'user:123',
            'editor',
            'document:456',
            'main',
            0.030,
        );

        $this->assertEquals('user:123', $event->user);
        $this->assertEquals('editor', $event->relation);
        $this->assertEquals('document:456', $event->object);
        $this->assertEquals('Granted: user:123#editor@document:456', $event->toString());
    }

    public function test_permission_revoked_event(): void
    {
        $event = new PermissionRevoked(
            'user:123',
            'editor',
            'document:456',
            'main',
            0.035,
        );

        $this->assertEquals('user:123', $event->user);
        $this->assertEquals('editor', $event->relation);
        $this->assertEquals('document:456', $event->object);
        $this->assertEquals('Revoked: user:123#editor@document:456', $event->toString());
    }

    public function test_relation_expanded_event(): void
    {
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

        $this->assertEquals('document:1', $event->object);
        $this->assertEquals('reader', $event->relation);
        $this->assertEquals($result, $event->result);

        $users = $event->getUsers();
        $this->assertCount(2, $users);
        $this->assertContains('user:123', $users);
        $this->assertContains('user:456', $users);
    }

    public function test_relation_expanded_event_with_complex_structure(): void
    {
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
        $this->assertCount(4, $users);
        $this->assertContains('user:1', $users);
        $this->assertContains('user:2', $users);
        $this->assertContains('user:3', $users);
        $this->assertContains('user:4', $users);
    }
}
