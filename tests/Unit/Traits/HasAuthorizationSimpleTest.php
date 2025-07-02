<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Traits;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OpenFGA\Laravel\Tests\Support\{TestDocument, TestUser};
use OpenFGA\Laravel\Tests\TestCase;
use ReflectionClass;

uses(TestCase::class);

describe('HasAuthorization Trait Simple Tests', function (): void {
    beforeEach(function (): void {
        $this->document = new TestDocument(['id' => 123]);
        $this->user = new TestUser(['id' => 456]);
    });

    describe('Authorization Object Generation', function (): void {
        it('generates correct authorization object', function (): void {
            expect($this->document->authorizationObject())->toBe('test_document:123');
        });

        it('generates correct authorization type', function (): void {
            expect($this->document->authorizationType())->toBe('test_document');
        });

        it('provides default authorization relations', function (): void {
            expect($this->document->getAuthorizationRelations())->toBe(['owner', 'editor', 'viewer']);
        });

        it('generates authorization object for different model types', function (): void {
            $user = new TestUser(['id' => 999]);
            expect($user->authorizationObject())->toBe('test_user:999');
            expect($user->authorizationType())->toBe('test_user');
        });
    });

    describe('User ID Resolution', function (): void {
        it('resolves user from model with trait', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect($method->invoke($this->document, $this->user))->toBe('test_user:456');
        });

        it('resolves user from string', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect($method->invoke($this->document, 'user:789'))->toBe('user:789');
        });

        it('resolves user from integer', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect($method->invoke($this->document, 999))->toBe('user:999');
        });

        it('resolves user from model without trait', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            // Create a model without the trait
            $mockModel = new class extends Model {
                protected $attributes = ['id' => 789];

                public function getKey()
                {
                    return $this->attributes['id'];
                }

                public function getTable()
                {
                    return 'test_model';
                }
            };

            // Anonymous classes have auto-generated names
            $result = $method->invoke($this->document, $mockModel);
            expect($result)->toEndWith(':789');
            expect($result)->toContain(':');
        });

        it('throws exception for invalid user type', function (): void {
            $reflection = new ReflectionClass($this->document);
            $method = $reflection->getMethod('resolveUserId');
            $method->setAccessible(true);

            expect(fn (): mixed => $method->invoke($this->document, []))
                ->toThrow(InvalidArgumentException::class, 'User must be a Model, string, or integer');
        });
    });

    describe('Model Events', function (): void {
        it('has initialization method', function (): void {
            expect(method_exists($this->document, 'initializeHasAuthorization'))->toBeTrue();
        });

        it('has protected methods', function (): void {
            $reflection = new ReflectionClass($this->document);

            expect($reflection->hasMethod('shouldCleanupPermissionsOnDelete'))->toBeTrue();
            expect($reflection->hasMethod('shouldReplicatePermissions'))->toBeTrue();
            expect($reflection->hasMethod('getOpenFgaManager'))->toBeTrue();
        });
    });

    describe('Public Methods', function (): void {
        it('has grant method', function (): void {
            expect(method_exists($this->document, 'grant'))->toBeTrue();
        });

        it('has revoke method', function (): void {
            expect(method_exists($this->document, 'revoke'))->toBeTrue();
        });

        it('has check method', function (): void {
            expect(method_exists($this->document, 'check'))->toBeTrue();
        });

        it('has can method', function (): void {
            expect(method_exists($this->document, 'can'))->toBeTrue();
        });

        it('has grantMany method', function (): void {
            expect(method_exists($this->document, 'grantMany'))->toBeTrue();
        });

        it('has revokeMany method', function (): void {
            expect(method_exists($this->document, 'revokeMany'))->toBeTrue();
        });

        it('has getUsersWithRelation method', function (): void {
            expect(method_exists($this->document, 'getUsersWithRelation'))->toBeTrue();
        });

        it('has getUserRelations method', function (): void {
            expect(method_exists($this->document, 'getUserRelations'))->toBeTrue();
        });

        it('has revokeAllPermissions method', function (): void {
            expect(method_exists($this->document, 'revokeAllPermissions'))->toBeTrue();
        });

        it('has replicatePermissionsTo method', function (): void {
            expect(method_exists($this->document, 'replicatePermissionsTo'))->toBeTrue();
        });
    });

    describe('Scope Methods', function (): void {
        it('has whereCurrentUserCan scope', function (): void {
            expect(method_exists($this->document, 'scopeWhereCurrentUserCan'))->toBeTrue();
        });

        it('has whereUserCan scope', function (): void {
            expect(method_exists($this->document, 'scopeWhereUserCan'))->toBeTrue();
        });
    });

    describe('Permission Replication', function (): void {
        it('throws exception when replicating to model without trait', function (): void {
            $invalidModel = new class extends Model {
                protected $attributes = ['id' => 999];
            };

            expect(fn () => $this->document->replicatePermissionsTo($invalidModel))
                ->toThrow(InvalidArgumentException::class, 'Target model must use HasAuthorization trait');
        });
    });
});
