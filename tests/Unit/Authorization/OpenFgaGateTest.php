<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenFGA\Laravel\Authorization\OpenFgaGate;
use OpenFGA\Laravel\Contracts\{AuthorizationObject, AuthorizationType, OpenFgaGateInterface};
use OpenFGA\Laravel\Tests\Support\{MockScenarios, TestAssertions, TestConstants, TestFactories};
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

use function expect;

enum OpenFgaGateTest
{
    case DELETE;

    case READ;

    case WRITE;
}

describe('OpenFgaGate', function (): void {
    beforeEach(function (): void {
        $this->container = $this->createMock(Container::class);
        $this->manager = TestFactories::createMockManager();
        $this->user = TestFactories::createTestUser(authId: TestConstants::DEFAULT_USER_ID);
        $this->userResolver = fn (): ?Authenticatable => $this->user;

        $this->gate = new OpenFgaGate(
            manager: $this->manager,
            container: $this->container,
            userResolver: $this->userResolver,
        );
    });

    describe('Interface Implementation', function (): void {
        it('implements OpenFgaGateInterface', function (): void {
            expect($this->gate)->toBeInstanceOf(OpenFgaGateInterface::class);
        });

        it('implements Laravel Gate contract', function (): void {
            expect($this->gate)->toBeInstanceOf(Gate::class);
        });
    });

    describe('check() method - Laravel compatibility', function (): void {
        it('handles single string ability with Laravel arguments', function (): void {
            // Traditional Laravel gate check should fall back to parent
            $result = $this->gate->check('view-posts', []);
            expect($result)->toBeBool();
        });

        it('handles iterable abilities with Laravel arguments', function (): void {
            $abilities = ['view-posts', 'edit-posts'];

            // Should delegate to parent for multiple abilities
            $result = $this->gate->check($abilities, []);
            expect($result)->toBeBool();
        });

        it('handles Collection of abilities', function (): void {
            $abilities = new Collection(['view-posts', 'edit-posts']);

            $result = $this->gate->check($abilities, []);
            expect($result)->toBeBool();
        });

        it('handles UnitEnum abilities', function (): void {
            // Create a test enum case for testing
            $enumCase = OpenFgaGateTest::READ;

            // Should delegate to parent for enum values
            $result = $this->gate->check($enumCase, []);
            expect($result)->toBeBool();
        });
    });

    describe('check() method - OpenFGA integration', function (): void {
        it('should return true when user has read permission on document using object:id format', function (): void {
            // Arrange: Set up manager to expect permission check and return success
            $this->manager
                ->shouldReceive('check')
                ->once()
                ->with(TestConstants::DEFAULT_USER_ID, 'read', TestConstants::DEFAULT_DOCUMENT_ID)
                ->andReturn(true);

            // Act: Check permission using object:id string format
            $result = $this->gate->check('read', TestConstants::DEFAULT_DOCUMENT_ID);

            // Assert: Permission check should succeed
            TestAssertions::assertUserCanAccess(
                $result,
                TestConstants::DEFAULT_USER_ID,
                'read',
                TestConstants::DEFAULT_DOCUMENT_ID,
            );
        });

        it('should return true when user has read permission on document using model instance', function (): void {
            // Arrange: Create a test document model with authorization capabilities
            $document = TestFactories::createTestDocument(
                objectId: TestConstants::DEFAULT_DOCUMENT_ID,
                identifier: 456,
            );

            // Verify the model has proper authorization capabilities
            TestAssertions::assertModelHasAuthorizationCapabilities(
                $document,
                TestConstants::DEFAULT_DOCUMENT_ID,
            );

            // Set up manager to expect permission check for this specific document
            $this->manager
                ->shouldReceive('check')
                ->once()
                ->with(TestConstants::DEFAULT_USER_ID, 'read', TestConstants::DEFAULT_DOCUMENT_ID)
                ->andReturn(true);

            // Act: Check permission using model instance
            $result = $this->gate->check('read', $document);

            // Assert: Permission check should succeed for model-based check
            TestAssertions::assertUserCanAccess(
                $result,
                TestConstants::DEFAULT_USER_ID,
                'read',
                TestConstants::DEFAULT_DOCUMENT_ID,
            );
        });

        it('should return false when user lacks write permission on document using array format', function (): void {
            // Arrange: Set up manager to deny write permission for this document
            $this->manager = MockScenarios::managerExpectingCalls([
                'check' => [
                    'times' => 1,
                    'with' => [TestConstants::DEFAULT_USER_ID, 'write', 'document:789'],
                    'andReturn' => false,
                ],
            ]);

            // Recreate gate with new manager mock that denies permission
            $this->gate = new OpenFgaGate(
                manager: $this->manager,
                container: $this->container,
                userResolver: $this->userResolver,
            );

            // Act: Check permission using array format (first element should be extracted)
            $result = $this->gate->check('write', ['document:789', 'extra-param']);

            // Assert: Permission check should fail for unauthorized write access
            TestAssertions::assertUserCannotAccess(
                $result,
                TestConstants::DEFAULT_USER_ID,
                'write',
                'document:789',
            );
        });

        it('handles custom user via forUser method', function (): void {
            $customUser = TestFactories::createTestUser(
                authId: TestConstants::ALTERNATIVE_USER_ID,
                identifier: 999,
            );

            $this->manager
                ->shouldReceive('check')
                ->once()
                ->with('user:999', 'admin', 'system:1')
                ->andReturn(true);

            // Use Laravel's standard way to check permissions for a different user
            $result = $this->gate->forUser($customUser)->check('admin', 'system:1');
            expect($result)->toBeTrue();
        });
    });

    describe('isOpenFgaPermission() method', function (): void {
        it('returns true for object:id string format', function (): void {
            expect($this->gate->isOpenFgaPermission('document:123'))->toBeTrue();
            expect($this->gate->isOpenFgaPermission('user:456'))->toBeTrue();
            expect($this->gate->isOpenFgaPermission('system:admin'))->toBeTrue();
        });

        it('returns true for array containing object:id format', function (): void {
            expect($this->gate->isOpenFgaPermission(['document:123']))->toBeTrue();
            expect($this->gate->isOpenFgaPermission(['document:123', 'extra']))->toBeTrue();
        });

        it('returns true for models with authorization methods', function (): void {
            $model = new class extends Model implements AuthorizationObject {
                public function authorizationObject(): string
                {
                    return 'test:123';
                }
            };

            expect($this->gate->isOpenFgaPermission($model))->toBeTrue();
        });

        it('returns false for regular Laravel arguments', function (): void {
            expect($this->gate->isOpenFgaPermission('simple-string'))->toBeFalse();
            expect($this->gate->isOpenFgaPermission(['param1', 'param2']))->toBeFalse();
            expect($this->gate->isOpenFgaPermission(123))->toBeFalse();
            expect($this->gate->isOpenFgaPermission(null))->toBeFalse();
        });

        it('returns true for basic models since they can be used with OpenFGA', function (): void {
            $model = TestFactories::createTestDocument(identifier: 123);

            expect($this->gate->isOpenFgaPermission($model))->toBeTrue();
        });
    });

    describe('checkOpenFgaPermission() method', function (): void {
        it('resolves different argument types correctly', function (): void {
            // Test string format
            $this->manager
                ->shouldReceive('check')
                ->twice()
                ->andReturnUsing(function ($user, $ability, $object) {
                    if ('user:123' === $user && 'read' === $ability && 'document:123' === $object) {
                        return true;
                    }

                    return (bool) ('user:123' === $user && 'read' === $ability && 'document:456' === $object);
                });

            $result = $this->gate->checkOpenFgaPermission('read', 'document:123');
            expect($result)->toBeTrue();

            // Test array format
            $result2 = $this->gate->checkOpenFgaPermission('read', ['document:456']);
            expect($result2)->toBeTrue();
        });

        it('handles model with AuthorizationType interface', function (): void {
            $model = new class extends Model implements AuthorizationType {
                public $id = 789;

                protected $table = 'documents';

                public function authorizationType(): string
                {
                    return 'custom_document';
                }

                public function getKey(): mixed
                {
                    return $this->id;
                }
            };

            $this->manager
                ->shouldReceive('check')
                ->once()
                ->with('user:123', 'delete', 'custom_document:789')
                ->andReturn(true);

            $result = $this->gate->checkOpenFgaPermission('delete', $model);
            expect($result)->toBeTrue();
        });

        it('handles regular Eloquent model', function (): void {
            $model = new class extends Model {
                public $id = 999;

                protected $table = 'posts';

                public function getKey(): mixed
                {
                    return $this->id;
                }
            };

            $this->manager
                ->shouldReceive('check')
                ->once()
                ->with('user:123', 'view', 'posts:999')
                ->andReturn(false);

            $result = $this->gate->checkOpenFgaPermission('view', $model);
            expect($result)->toBeFalse();
        });

        it('returns false when no valid object found', function (): void {
            $result = $this->gate->checkOpenFgaPermission('read', 'invalid-format');
            expect($result)->toBeFalse();
        });

        it('returns false when user is null', function (): void {
            $this->userResolver = fn (): ?Authenticatable => null;
            $gate = new OpenFgaGate(
                manager: $this->manager,
                container: $this->container,
                userResolver: $this->userResolver,
            );

            $result = $gate->checkOpenFgaPermission('read', 'document:123');
            expect($result)->toBeFalse();
        });
    });

    describe('Edge cases and error handling', function (): void {
        it('handles mixed argument types in array', function (): void {
            $model = new class extends Model implements AuthorizationObject {
                public function authorizationObject(): string
                {
                    return 'model:123';
                }
            };

            $arguments = [
                'non-object-string',
                $model,
                123,
                null,
            ];

            $this->manager
                ->shouldReceive('check')
                ->once()
                ->with('user:123', 'access', 'model:123')
                ->andReturn(true);

            $result = $this->gate->checkOpenFgaPermission('access', $arguments);
            expect($result)->toBeTrue();
        });

        it('handles user with different authorization methods', function (): void {
            $userWithMethod = new class implements Authenticatable {
                public function getAuthIdentifier(): mixed
                {
                    return 'custom-id';
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthorizationUserId(): string
                {
                    return 'custom:user:id';
                }

                public function getAuthPassword(): ?string
                {
                    return null;
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): ?string
                {
                    return null;
                }

                public function getRememberTokenName(): ?string
                {
                    return null;
                }

                public function setRememberToken($value): void
                {
                }
            };

            $this->manager
                ->shouldReceive('check')
                ->once()
                ->with('custom:user:id', 'read', 'document:123')
                ->andReturn(true);

            $result = $this->gate->checkOpenFgaPermission('read', 'document:123', $userWithMethod);
            expect($result)->toBeTrue();
        });

        it('falls back to auth identifier when no custom methods available', function (): void {
            $basicUser = new class implements Authenticatable {
                public function getAuthIdentifier(): mixed
                {
                    return 'basic-user';
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthPassword(): ?string
                {
                    return null;
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): ?string
                {
                    return null;
                }

                public function getRememberTokenName(): ?string
                {
                    return null;
                }

                public function setRememberToken($value): void
                {
                }
            };

            $this->manager
                ->shouldReceive('check')
                ->once()
                ->with('user:basic-user', 'read', 'document:123')
                ->andReturn(true);

            $result = $this->gate->checkOpenFgaPermission('read', 'document:123', $basicUser);
            expect($result)->toBeTrue();
        });

        it('handles model with null key gracefully', function (): void {
            $model = new class extends Model {
                protected $table = 'items';

                public function getKey(): mixed
                {
                    return null;
                }
            };

            // Should return false because model has null key
            $result = $this->gate->checkOpenFgaPermission('read', $model);
            expect($result)->toBeFalse();
        });

        it('prioritizes string object:id format over model in mixed array', function (): void {
            $model = new class extends Model implements AuthorizationObject {
                public function authorizationObject(): string
                {
                    return 'model:wrong';
                }
            };

            $arguments = ['document:correct', $model]; // Put string first to ensure it's found first

            $this->manager
                ->shouldReceive('check')
                ->once()
                ->with('user:123', 'read', 'document:correct')
                ->andReturn(true);

            $result = $this->gate->checkOpenFgaPermission('read', $arguments);
            expect($result)->toBeTrue();
        });
    });

    describe('Integration with Laravel Gate methods', function (): void {
        it('allows() method works with OpenFGA permissions', function (): void {
            $this->manager
                ->shouldReceive('check')
                ->once()
                ->with('user:123', 'read', 'document:123')
                ->andReturn(true);

            $result = $this->gate->allows('read', 'document:123');
            expect($result)->toBeTrue();
        });

        it('denies() method works with OpenFGA permissions', function (): void {
            $this->manager
                ->shouldReceive('check')
                ->once()
                ->with('user:123', 'write', 'document:123')
                ->andReturn(false);

            $result = $this->gate->denies('write', 'document:123');
            expect($result)->toBeTrue();
        });

        it('any() method works with mixed abilities', function (): void {
            // This should fall back to parent behavior
            $result = $this->gate->any(['read', 'write'], []);
            expect($result)->toBeBool();
        });
    });
});
