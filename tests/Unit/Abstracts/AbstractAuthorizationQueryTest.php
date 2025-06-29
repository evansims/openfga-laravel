<?php

declare(strict_types=1);

use OpenFGA\Laravel\Abstracts\AbstractAuthorizationQuery;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Models\Collections\TupleKeys;

uses(TestCase::class);

describe('AbstractAuthorizationQuery', function (): void {
    beforeEach(function (): void {
        $this->manager = Mockery::mock(ManagerInterface::class);
        $this->connection = 'test-connection';

        // Create a concrete implementation for testing
        $this->query = new class($this->manager, $this->connection) extends AbstractAuthorizationQuery {
            public function fresh(): static
            {
                return new self($this->manager, $this->connection);
            }

            // Expose protected methods for testing
            public function validateCheckQuery(): void
            {
                parent::validateCheckQuery();
            }

            public function validateWriteQuery(): void
            {
                parent::validateWriteQuery();
            }
        };
    });

    it('is abstract class', function (): void {
        $reflection = new ReflectionClass(AbstractAuthorizationQuery::class);
        expect($reflection->isAbstract())->toBeTrue();
    });

    it('has abstract fresh method', function (): void {
        $reflection = new ReflectionClass(AbstractAuthorizationQuery::class);
        $method = $reflection->getMethod('fresh');
        expect($method->isAbstract())->toBeTrue();
    });

    it('stores manager and connection', function (): void {
        $reflection = new ReflectionClass($this->query);
        $managerProperty = $reflection->getProperty('manager');
        $connectionProperty = $reflection->getProperty('connection');

        expect($managerProperty->getValue($this->query))->toBe($this->manager);
        expect($connectionProperty->getValue($this->query))->toBe($this->connection);
    });

    describe('fluent interface methods', function (): void {
        it('sets user with for method', function (): void {
            $result = $this->query->for('user:123');
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $userProperty = $reflection->getProperty('user');
            expect($userProperty->getValue($this->query))->toBe('user:123');
        });

        it('sets user with user method', function (): void {
            $result = $this->query->user('user:456');
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $userProperty = $reflection->getProperty('user');
            expect($userProperty->getValue($this->query))->toBe('user:456');
        });

        it('sets relation with can method', function (): void {
            $result = $this->query->can('read');
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $relationProperty = $reflection->getProperty('relation');
            expect($relationProperty->getValue($this->query))->toBe('read');
        });

        it('sets relation with relation method', function (): void {
            $result = $this->query->relation('write');
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $relationProperty = $reflection->getProperty('relation');
            expect($relationProperty->getValue($this->query))->toBe('write');
        });

        it('sets object with on method', function (): void {
            $result = $this->query->on('doc:123');
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $objectProperty = $reflection->getProperty('object');
            expect($objectProperty->getValue($this->query))->toBe('doc:123');
        });

        it('sets object with object method', function (): void {
            $result = $this->query->object('doc:456');
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $objectProperty = $reflection->getProperty('object');
            expect($objectProperty->getValue($this->query))->toBe('doc:456');
        });

        it('sets type', function (): void {
            $result = $this->query->type('document');
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $typeProperty = $reflection->getProperty('type');
            expect($typeProperty->getValue($this->query))->toBe('document');
        });
    });

    describe('contextual data methods', function (): void {
        it('adds contextual tuples', function (): void {
            $tuples = [
                ['user' => 'user:123', 'relation' => 'member', 'object' => 'group:admin'],
            ];

            $result = $this->query->withContext($tuples);
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $contextualTuplesProperty = $reflection->getProperty('contextualTuples');
            expect($contextualTuplesProperty->getValue($this->query))->toBe($tuples);
        });

        it('adds single contextual tuple', function (): void {
            $result = $this->query->withTuple('user:123', 'member', 'group:admin');
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $contextualTuplesProperty = $reflection->getProperty('contextualTuples');
            $tuples = $contextualTuplesProperty->getValue($this->query);

            expect($tuples)->toHaveCount(1);
            expect($tuples[0])->toBe([
                'user' => 'user:123',
                'relation' => 'member',
                'object' => 'group:admin',
            ]);
        });

        it('sets context object', function (): void {
            $context = (object) ['key' => 'value'];
            $result = $this->query->withContextObject($context);
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $contextProperty = $reflection->getProperty('context');
            expect($contextProperty->getValue($this->query))->toBe($context);
        });

        it('accumulates contextual tuples', function (): void {
            $this->query->withTuple('user:123', 'member', 'group:admin');
            $this->query->withContext([
                ['user' => 'user:456', 'relation' => 'owner', 'object' => 'doc:789'],
            ]);

            $reflection = new ReflectionClass($this->query);
            $contextualTuplesProperty = $reflection->getProperty('contextualTuples');
            $tuples = $contextualTuplesProperty->getValue($this->query);

            expect($tuples)->toHaveCount(2);
        });
    });

    describe('filter methods', function (): void {
        it('filters by single relation', function (): void {
            $result = $this->query->whereRelation('read');
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $relationsProperty = $reflection->getProperty('relations');
            expect($relationsProperty->getValue($this->query))->toBe(['read']);
        });

        it('filters by multiple relations', function (): void {
            $result = $this->query->whereRelation(['read', 'write']);
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $relationsProperty = $reflection->getProperty('relations');
            expect($relationsProperty->getValue($this->query))->toBe(['read', 'write']);
        });

        it('filters by single user type', function (): void {
            $result = $this->query->whereUserType('user');
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $userTypesProperty = $reflection->getProperty('userTypes');
            expect($userTypesProperty->getValue($this->query))->toBe(['user']);
        });

        it('filters by multiple user types', function (): void {
            $result = $this->query->whereUserType(['user', 'service']);
            expect($result)->toBe($this->query);

            $reflection = new ReflectionClass($this->query);
            $userTypesProperty = $reflection->getProperty('userTypes');
            expect($userTypesProperty->getValue($this->query))->toBe(['user', 'service']);
        });

        it('accumulates relation filters', function (): void {
            $this->query->whereRelation('read');
            $this->query->whereRelation(['write', 'admin']);

            $reflection = new ReflectionClass($this->query);
            $relationsProperty = $reflection->getProperty('relations');
            expect($relationsProperty->getValue($this->query))->toBe(['read', 'write', 'admin']);
        });

        it('accumulates user type filters', function (): void {
            $this->query->whereUserType('user');
            $this->query->whereUserType(['service', 'admin']);

            $reflection = new ReflectionClass($this->query);
            $userTypesProperty = $reflection->getProperty('userTypes');
            expect($userTypesProperty->getValue($this->query))->toBe(['user', 'service', 'admin']);
        });
    });

    describe('validation methods', function (): void {
        it('validates check query with all required fields', function (): void {
            $this->query->for('user:123')->can('read')->on('doc:456');

            // Should not throw - we just run it and expect no exception
            $this->query->validateCheckQuery();
            expect(true)->toBeTrue(); // If we get here, validation passed
        });

        it('throws exception for check query missing user', function (): void {
            $this->query->can('read')->on('doc:456');

            expect(fn () => $this->query->validateCheckQuery())
                ->toThrow(InvalidArgumentException::class, 'User is required for check query');
        });

        it('throws exception for check query missing relation', function (): void {
            $this->query->for('user:123')->on('doc:456');

            expect(fn () => $this->query->validateCheckQuery())
                ->toThrow(InvalidArgumentException::class, 'Relation is required for check query');
        });

        it('throws exception for check query missing object', function (): void {
            $this->query->for('user:123')->can('read');

            expect(fn () => $this->query->validateCheckQuery())
                ->toThrow(InvalidArgumentException::class, 'Object is required for check query');
        });

        it('validates write query with all required fields', function (): void {
            $this->query->for('user:123')->can('read')->on('doc:456');

            // Should not throw - we just run it and expect no exception
            $this->query->validateWriteQuery();
            expect(true)->toBeTrue(); // If we get here, validation passed
        });

        it('throws exception for write query missing user', function (): void {
            $this->query->can('read')->on('doc:456');

            expect(fn () => $this->query->validateWriteQuery())
                ->toThrow(InvalidArgumentException::class, 'User is required for write query');
        });

        it('throws exception for write query missing relation', function (): void {
            $this->query->for('user:123')->on('doc:456');

            expect(fn () => $this->query->validateWriteQuery())
                ->toThrow(InvalidArgumentException::class, 'Relation is required for write query');
        });

        it('throws exception for write query missing object', function (): void {
            $this->query->for('user:123')->can('read');

            expect(fn () => $this->query->validateWriteQuery())
                ->toThrow(InvalidArgumentException::class, 'Object is required for write query');
        });
    });

    describe('execution methods', function (): void {
        it('executes check with manager', function (): void {
            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'doc:456', [], [], 'test-connection')
                ->once()
                ->andReturn(true);

            $this->query->for('user:123')->can('read')->on('doc:456');
            $result = $this->query->check();

            expect($result)->toBeTrue();
        });

        it('executes check with context', function (): void {
            $context = (object) ['key' => 'value'];
            $contextualTuples = [
                ['user' => 'user:456', 'relation' => 'member', 'object' => 'group:admin'],
            ];

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'doc:456', $contextualTuples, ['key' => 'value'], 'test-connection')
                ->once()
                ->andReturn(false);

            $this->query
                ->for('user:123')
                ->can('read')
                ->on('doc:456')
                ->withContextObject($context)
                ->withContext($contextualTuples);

            $result = $this->query->check();
            expect($result)->toBeFalse();
        });

        it('executes batch check', function (): void {
            $checks = [
                ['user' => 'user:123', 'relation' => 'read', 'object' => 'doc:456'],
                ['user' => 'user:456', 'relation' => 'write', 'object' => 'doc:789'],
            ];

            $this->manager->shouldReceive('batchCheck')
                ->with($checks, 'test-connection')
                ->once()
                ->andReturn(['user:123:read:doc:456' => true, 'user:456:write:doc:789' => false]);

            $result = $this->query->checkMany($checks);
            expect($result)->toBeArray();
        });

        it('executes batch check with defaults', function (): void {
            $this->query->for('user:123')->can('read');

            $checks = [
                ['object' => 'doc:456'],
                ['object' => 'doc:789'],
            ];

            $expectedChecks = [
                ['user' => 'user:123', 'relation' => 'read', 'object' => 'doc:456'],
                ['user' => 'user:123', 'relation' => 'read', 'object' => 'doc:789'],
            ];

            $this->manager->shouldReceive('batchCheck')
                ->with($expectedChecks, 'test-connection')
                ->once()
                ->andReturn([]);

            $this->query->checkMany($checks);
        });

        it('throws exception for invalid batch check', function (): void {
            $checks = [
                ['user' => 'user:123'], // Missing relation and object
            ];

            expect(fn () => $this->query->checkMany($checks))
                ->toThrow(InvalidArgumentException::class, 'User, relation, and object are required for each check');
        });

        it('executes listObjects', function (): void {
            $this->manager->shouldReceive('listObjects')
                ->with('user:123', 'read', 'document', [], [], 'test-connection')
                ->once()
                ->andReturn(['doc:456', 'doc:789']);

            $this->query->for('user:123')->can('read')->type('document');
            $result = $this->query->listObjects();

            expect($result)->toBe(['doc:456', 'doc:789']);
        });

        it('throws exception for listObjects missing user', function (): void {
            $this->query->can('read')->type('document');

            expect(fn () => $this->query->listObjects())
                ->toThrow(InvalidArgumentException::class, 'User is required for listObjects query');
        });

        it('throws exception for listObjects missing relation', function (): void {
            $this->query->for('user:123')->type('document');

            expect(fn () => $this->query->listObjects())
                ->toThrow(InvalidArgumentException::class, 'Relation is required for listObjects query');
        });

        it('throws exception for listObjects missing type', function (): void {
            $this->query->for('user:123')->can('read');

            expect(fn () => $this->query->listObjects())
                ->toThrow(InvalidArgumentException::class, 'Type is required for listObjects query');
        });

        it('executes listRelations', function (): void {
            $this->manager->shouldReceive('listRelations')
                ->with('user:123', 'doc:456', ['read', 'write'], [], [], 'test-connection')
                ->once()
                ->andReturn(['read' => true, 'write' => false]);

            $this->query
                ->for('user:123')
                ->on('doc:456')
                ->whereRelation(['read', 'write']);

            $result = $this->query->listRelations();
            expect($result)->toBe(['read' => true, 'write' => false]);
        });

        it('throws exception for listRelations missing user', function (): void {
            $this->query->on('doc:456');

            expect(fn () => $this->query->listRelations())
                ->toThrow(InvalidArgumentException::class, 'User is required for listRelations query');
        });

        it('throws exception for listRelations missing object', function (): void {
            $this->query->for('user:123');

            expect(fn () => $this->query->listRelations())
                ->toThrow(InvalidArgumentException::class, 'Object is required for listRelations query');
        });

        it('executes listUsers', function (): void {
            $this->manager->shouldReceive('listUsers')
                ->with('doc:456', 'read', ['user', 'service'], [], [], 'test-connection')
                ->once()
                ->andReturn(['user:123', 'service:456']);

            $this->query
                ->on('doc:456')
                ->can('read')
                ->whereUserType(['user', 'service']);

            $result = $this->query->listUsers();
            expect($result)->toBe(['user:123', 'service:456']);
        });

        it('throws exception for listUsers missing object', function (): void {
            $this->query->can('read');

            expect(fn () => $this->query->listUsers())
                ->toThrow(InvalidArgumentException::class, 'Object is required for listUsers query');
        });

        it('throws exception for listUsers missing relation', function (): void {
            $this->query->on('doc:456');

            expect(fn () => $this->query->listUsers())
                ->toThrow(InvalidArgumentException::class, 'Relation is required for listUsers query');
        });
    });

    describe('write operations', function (): void {
        it('executes single grant', function (): void {
            $this->manager->shouldReceive('grant')
                ->with('user:123', 'read', 'doc:456', 'test-connection')
                ->once()
                ->andReturn(true);

            $this->query->for('user:123')->can('read')->on('doc:456');
            $result = $this->query->grant();

            expect($result)->toBeTrue();
        });

        it('executes batch grant', function (): void {
            $this->manager->shouldReceive('write')
                ->with(Mockery::type(TupleKeys::class), null, 'test-connection')
                ->once()
                ->andReturn(true);

            $grants = [
                ['user' => 'user:123', 'relation' => 'read', 'object' => 'doc:456'],
                ['user' => 'user:456', 'relation' => 'write', 'object' => 'doc:789'],
            ];

            $result = $this->query->grant($grants);
            expect($result)->toBeTrue();
        });

        it('throws exception for invalid grant', function (): void {
            $grants = [
                ['user' => 'user:123'], // Missing relation and object
            ];

            expect(fn () => $this->query->grant($grants))
                ->toThrow(InvalidArgumentException::class, 'User, relation, and object are required for each grant');
        });

        it('executes single revoke', function (): void {
            $this->manager->shouldReceive('revoke')
                ->with('user:123', 'read', 'doc:456', 'test-connection')
                ->once()
                ->andReturn(true);

            $this->query->for('user:123')->can('read')->on('doc:456');
            $result = $this->query->revoke();

            expect($result)->toBeTrue();
        });

        it('executes batch revoke', function (): void {
            $this->manager->shouldReceive('write')
                ->with(null, Mockery::type(TupleKeys::class), 'test-connection')
                ->once()
                ->andReturn(true);

            $revokes = [
                ['user' => 'user:123', 'relation' => 'read', 'object' => 'doc:456'],
                ['user' => 'user:456', 'relation' => 'write', 'object' => 'doc:789'],
            ];

            $result = $this->query->revoke($revokes);
            expect($result)->toBeTrue();
        });

        it('throws exception for invalid revoke', function (): void {
            $revokes = [
                ['user' => 'user:123'], // Missing relation and object
            ];

            expect(fn () => $this->query->revoke($revokes))
                ->toThrow(InvalidArgumentException::class, 'User, relation, and object are required for each revoke');
        });
    });

    describe('concrete implementation', function (): void {
        it('implements fresh method', function (): void {
            $fresh = $this->query->fresh();
            expect($fresh)->toBeInstanceOf(get_class($this->query));
            expect($fresh)->not()->toBe($this->query);
        });
    });
});
