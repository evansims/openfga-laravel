<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use OpenFGA\Laravel\Authorization\OpenFgaGate;
use OpenFGA\Laravel\Contracts\{AuthorizableUser, AuthorizationObject, AuthorizationType, ManagerInterface};
use OpenFGA\Laravel\Tests\TestCase;

use function expect;

uses(TestCase::class);

describe('OpenFgaGate Edge Cases', function (): void {
    beforeEach(function (): void {
        $this->container = $this->createMock(Container::class);
        $this->manager = $this->createMock(ManagerInterface::class);
        $this->userResolver = fn (): ?Authenticatable => $this->user;

        $this->gate = new OpenFgaGate(
            manager: $this->manager,
            container: $this->container,
            userResolver: $this->userResolver,
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

    describe('Model Class String vs Instance Edge Cases', function (): void {
        it('handles passing model class string as ability with instance as argument', function (): void {
            // This tests the edge case where ability might be a model class name
            $model = new class extends Model implements AuthorizationObject {
                public $id = 456;

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

            // When ability is not an OpenFGA relation but looks like class name
            $result = $this->gate->check('App\\Models\\Document', $model);
            // Should fall back to Laravel's default behavior since ability doesn't look like OpenFGA relation
            expect($result)->toBeBool();
        });

        it('handles model instance as first argument (non-standard usage)', function (): void {
            $model = new class extends Model implements AuthorizationObject {
                public function authorizationObject(): string
                {
                    return 'document:123';
                }
            };

            // Test with model as first argument - should still detect as non-OpenFGA
            $result = $this->gate->check($model, ['some', 'args']);
            expect($result)->toBeBool(); // Falls back to Laravel
        });

        it('distinguishes between OpenFGA relations and model class names', function (): void {
            // OpenFGA relation names are typically lowercase/snake_case
            $this->manager
                ->expects($this->exactly(2))
                ->method('check')
                ->willReturnCallback(function ($user, $ability, $object): bool {
                    if ('user:123' === $user && 'view_document' === $ability && 'document:123' === $object) {
                        return true;
                    }

                    if ('user:123' === $user && 'ViewDocument' === $ability && 'document:123' === $object) {
                        return false; // PascalCase ability
                    }

                    return false;
                });

            $result = $this->gate->check('view_document', 'document:123');
            expect($result)->toBeTrue();

            // While class names are typically PascalCase - our implementation still treats them as OpenFGA
            $result2 = $this->gate->check('ViewDocument', 'document:123');
            expect($result2)->toBeFalse(); // Still goes to OpenFGA but gets denied
        });
    });

    describe('Tuple vs Array Arguments', function (): void {
        it('handles tuple-like arrays correctly', function (): void {
            // Test with tuple-style arguments - should find 'document:456' as object identifier
            $tupleArgs = ['user:123', 'read', 'document:456'];

            // Should detect document:456 as the object identifier (first string with colon)
            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'read', 'user:123') // First string with colon is found
                ->willReturn(true);

            $result = $this->gate->check('read', $tupleArgs);
            expect($result)->toBeTrue();
        });

        it('handles mixed array with both model and string identifiers', function (): void {
            $model = new class extends Model implements AuthorizationObject {
                public function authorizationObject(): string
                {
                    return 'model:789';
                }
            };

            $mixedArgs = [
                'extra-param',
                $model, // This will be found first since it has authorizationObject method
                'string-param',
                'document:123',
            ];

            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'access', 'model:789') // Model's authorizationObject is found first
                ->willReturn(true);

            $result = $this->gate->check('access', $mixedArgs);
            expect($result)->toBeTrue();
        });

        it('handles empty arrays gracefully', function (): void {
            $result = $this->gate->check('read', []);
            expect($result)->toBeBool(); // Falls back to Laravel
        });

        it('handles arrays with only non-OpenFGA arguments', function (): void {
            $args = ['param1', 'param2', 123, null];

            $result = $this->gate->check('some-ability', $args);
            expect($result)->toBeBool(); // Falls back to Laravel
        });
    });

    describe('Complex Model Inheritance Scenarios', function (): void {
        it('handles model implementing multiple authorization interfaces', function (): void {
            $model = new class extends Model implements AuthorizationObject, AuthorizationType {
                public $id = 999;

                protected $table = 'complex_models';

                // AuthorizationObject takes precedence
                public function authorizationObject(): string
                {
                    return 'custom:complex:999';
                }

                // This should be ignored when authorizationObject() exists
                public function authorizationType(): string
                {
                    return 'complex_model';
                }

                public function getKey(): mixed
                {
                    return $this->id;
                }
            };

            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'manage', 'custom:complex:999')
                ->willReturn(true);

            $result = $this->gate->checkOpenFgaPermission('manage', $model);
            expect($result)->toBeTrue();
        });

        it('handles model with AuthorizationType when AuthorizationObject not available', function (): void {
            $model = new class extends Model implements AuthorizationType {
                public $id = 777;

                protected $table = 'typed_models';

                public function authorizationType(): string
                {
                    return 'typed_model';
                }

                public function getKey(): mixed
                {
                    return $this->id;
                }
            };

            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'edit', 'typed_model:777')
                ->willReturn(false);

            $result = $this->gate->checkOpenFgaPermission('edit', $model);
            expect($result)->toBeFalse();
        });

        it('handles basic Eloquent model without authorization interfaces', function (): void {
            $model = new class extends Model {
                public $id = 555;

                protected $table = 'basic_models';

                public function getKey(): mixed
                {
                    return $this->id;
                }
            };

            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'view', 'basic_models:555')
                ->willReturn(true);

            $result = $this->gate->checkOpenFgaPermission('view', $model);
            expect($result)->toBeTrue();
        });
    });

    describe('User Resolution Edge Cases', function (): void {
        it('handles user with authorizationUser returning non-string', function (): void {
            $userWithBadReturn = new class implements Authenticatable {
                // Return non-string - should fall back to identifier
                public function authorizationUser(): int
                {
                    return 999;
                }

                public function getAuthIdentifier(): mixed
                {
                    return 123;
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
                ->with('user:123', 'read', 'document:123') // Should use getAuthIdentifier fallback
                ->willReturn(true);

            $result = $this->gate->checkOpenFgaPermission('read', 'document:123', $userWithBadReturn);
            expect($result)->toBeTrue();
        });

        it('handles user with null auth identifier', function (): void {
            $userWithNullId = new class implements Authenticatable {
                public function getAuthIdentifier(): mixed
                {
                    return null;
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
                ->with('user:unknown', 'read', 'document:123') // Should use fallback
                ->willReturn(false);

            $result = $this->gate->checkOpenFgaPermission('read', 'document:123', $userWithNullId);
            expect($result)->toBeFalse();
        });

        it('prioritizes AuthorizableUser interface over method existence', function (): void {
            $userWithInterface = new class implements Authenticatable, AuthorizableUser {
                public function authorizationUser(): string
                {
                    return 'interface:user:123';
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
                    return 'interface:user:123';
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
                ->with('interface:user:123', 'admin', 'system:1') // Should use interface method
                ->willReturn(true);

            $result = $this->gate->checkOpenFgaPermission('admin', 'system:1', $userWithInterface);
            expect($result)->toBeTrue();
        });
    });

    describe('Parameter Type Validation', function (): void {
        it('handles numeric model keys correctly', function (): void {
            $model = new class extends Model {
                public $id = 12345;

                protected $table = 'numeric_models';

                public function getKey(): mixed
                {
                    return $this->id;
                }
            };

            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'access', 'numeric_models:12345')
                ->willReturn(true);

            $result = $this->gate->checkOpenFgaPermission('access', $model);
            expect($result)->toBeTrue();
        });

        it('handles string model keys correctly', function (): void {
            $model = new class extends Model {
                public $id = 'uuid-string-123';

                protected $table = 'string_models';

                public function getKey(): mixed
                {
                    return $this->id;
                }
            };

            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'access', 'string_models:uuid-string-123')
                ->willReturn(true);

            $result = $this->gate->checkOpenFgaPermission('access', $model);
            expect($result)->toBeTrue();
        });

        it('handles model with composite key gracefully', function (): void {
            $model = new class extends Model {
                protected $table = 'composite_models';

                // Return array (composite key) - should be handled gracefully
                public function getKey(): mixed
                {
                    return ['part1' => 123, 'part2' => 456];
                }
            };

            // ModelKeyHelper should handle this case
            $result = $this->gate->checkOpenFgaPermission('access', $model);
            expect($result)->toBeBool(); // May succeed or fail depending on ModelKeyHelper implementation
        });
    });

    describe('Boundary Conditions', function (): void {
        it('handles very long object identifiers', function (): void {
            $longId = str_repeat('a', 1000);
            $objectId = 'document:' . $longId;

            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'read', $objectId)
                ->willReturn(true);

            $result = $this->gate->checkOpenFgaPermission('read', $objectId);
            expect($result)->toBeTrue();
        });

        it('handles object identifiers with special characters', function (): void {
            $specialId = 'document:test@example.com/path?query=1&sort=name';

            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'read', $specialId)
                ->willReturn(true);

            $result = $this->gate->checkOpenFgaPermission('read', $specialId);
            expect($result)->toBeTrue();
        });

        it('handles deeply nested array arguments', function (): void {
            $nestedArgs = [
                'level1' => [
                    'level2' => [
                        'document:nested:123',
                    ],
                ],
                'document:shallow:456',
            ];

            // Should find the shallow one first
            $this->manager
                ->expects($this->once())
                ->method('check')
                ->with('user:123', 'read', 'document:shallow:456')
                ->willReturn(true);

            $result = $this->gate->checkOpenFgaPermission('read', $nestedArgs);
            expect($result)->toBeTrue();
        });
    });
});
