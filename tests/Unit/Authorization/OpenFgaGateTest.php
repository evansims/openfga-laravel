<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use OpenFGA\Laravel\Authorization\OpenFgaGate;
use OpenFGA\Laravel\Contracts\{AuthorizableUser, AuthorizationObject, AuthorizationType, ManagerInterface, OpenFgaGateInterface};

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
        $this->manager = $this->createMock(ManagerInterface::class);
        $this->userResolver = fn (): ?Authenticatable => $this->user;

        $this->gate = new OpenFgaGate(
            $this->manager,
            $this->container,
            $this->userResolver,
        );

        // Create test user
        $this->user = new class implements Authenticatable, AuthorizableUser {
            public function authorizationUser(): string
            {
                return 'user:123';
            }

            public function getAuthIdentifier(): mixed
            {
                return 123;
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthorizationUserId(): string
            {
                return 'user:123';
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
        it('detects OpenFGA permission check with object:id format', function (): void {
            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'read', 'document:456')
                ->willReturn(true);

            $result = $this->gate->check('read', 'document:456');
            expect($result)->toBeTrue();
        });

        it('detects OpenFGA permission check with model instance', function (): void {
            $model = new class extends Model implements AuthorizationObject {
                public $id = 456;

                protected $primaryKey = 'id';

                protected $table = 'documents';

                public function authorizationObject(): string
                {
                    return 'document:456';
                }

                public function getKey(): mixed
                {
                    return $this->id;
                }
            };

            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'read', 'document:456')
                ->willReturn(true);

            $result = $this->gate->check('read', $model);
            expect($result)->toBeTrue();
        });

        it('detects OpenFGA permission check with array containing object', function (): void {
            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'write', 'document:789')
                ->willReturn(false);

            $result = $this->gate->check('write', ['document:789', 'extra-param']);
            expect($result)->toBeFalse();
        });

        it('handles custom user parameter', function (): void {
            $customUser = new class implements Authenticatable, AuthorizableUser {
                public function authorizationUser(): string
                {
                    return 'user:999';
                }

                public function getAuthIdentifier(): mixed
                {
                    return 999;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthorizationUserId(): string
                {
                    return 'user:999';
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
                ->expects($this->once())
                ->method('check')
                ->with('user:999', 'admin', 'system:1')
                ->willReturn(true);

            $result = $this->gate->check('admin', 'system:1', $customUser);
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
            $model = new class extends Model {
                public $id = 123;

                protected $table = 'basic_models';

                public function getKey(): mixed
                {
                    return $this->id;
                }
            };

            expect($this->gate->isOpenFgaPermission($model))->toBeTrue();
        });
    });

    describe('checkOpenFgaPermission() method', function (): void {
        it('resolves different argument types correctly', function (): void {
            // Test string format
            $this->manager
                ->expects($this->exactly(2))
                ->method('check')
                ->willReturnCallback(function ($user, $ability, $object) {
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
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'delete', 'custom_document:789')
                ->willReturn(true);

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
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'view', 'posts:999')
                ->willReturn(false);

            $result = $this->gate->checkOpenFgaPermission('view', $model);
            expect($result)->toBeFalse();
        });

        it('returns false when no valid object found', function (): void {
            $result = $this->gate->checkOpenFgaPermission('read', 'invalid-format');
            expect($result)->toBeFalse();
        });

        it('returns false when user is null', function (): void {
            $this->userResolver = fn (): ?Authenticatable => null;
            $gate = new OpenFgaGate($this->manager, $this->container, $this->userResolver);

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
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'access', 'model:123')
                ->willReturn(true);

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
                ->expects($this->once())
                ->method('check')
                ->with('custom:user:id', 'read', 'document:123')
                ->willReturn(true);

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
                ->expects($this->once())
                ->method('check')
                ->with('user:basic-user', 'read', 'document:123')
                ->willReturn(true);

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
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'read', 'document:correct')
                ->willReturn(true);

            $result = $this->gate->checkOpenFgaPermission('read', $arguments);
            expect($result)->toBeTrue();
        });
    });

    describe('Integration with Laravel Gate methods', function (): void {
        it('allows() method works with OpenFGA permissions', function (): void {
            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'read', 'document:123')
                ->willReturn(true);

            $result = $this->gate->allows('read', 'document:123');
            expect($result)->toBeTrue();
        });

        it('denies() method works with OpenFGA permissions', function (): void {
            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'write', 'document:123')
                ->willReturn(false);

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
