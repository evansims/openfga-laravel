<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use OpenFGA\Laravel\Contracts\{AuthorizationUser, AuthorizationUserId};
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\Traits\ResolvesAuthorizationUser;

uses(TestCase::class);

describe('ResolvesAuthorizationUser', function (): void {
    beforeEach(function (): void {
        // Create a class that uses the trait for testing
        $this->resolver = new class {
            use ResolvesAuthorizationUser;

            public function testResolveUserIdentifier(Authenticatable $user): string
            {
                return $this->resolveUserIdentifier($user);
            }
        };
    });

    describe('resolveUserIdentifier', function (): void {
        it('uses authorizationUser method when available', function (): void {
            $user = new class implements Authenticatable, AuthorizationUser {
                public function authorizationUser(): string
                {
                    return 'custom:user:123';
                }

                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('custom:user:123');
        });

        it('handles authorizationUser returning numeric value', function (): void {
            $user = new class implements Authenticatable {
                public function authorizationUser(): int
                {
                    return 456;
                }

                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('456');
        });

        it('throws exception when authorizationUser returns invalid type', function (): void {
            $user = new class implements Authenticatable {
                public function authorizationUser(): array
                {
                    return ['invalid'];
                }

                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            expect(fn () => $this->resolver->testResolveUserIdentifier($user))
                ->toThrow(InvalidArgumentException::class, 'authorizationUser() must return a string or numeric value');
        });

        it('uses getAuthorizationUserId method when authorizationUser not available', function (): void {
            $user = new class implements Authenticatable, AuthorizationUserId {
                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthorizationUserId(): string
                {
                    return '789';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('789');
        });

        it('handles getAuthorizationUserId returning numeric value', function (): void {
            $user = new class implements Authenticatable {
                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthorizationUserId(): int
                {
                    return 999;
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('999');
        });

        it('throws exception when getAuthorizationUserId returns invalid type', function (): void {
            $user = new class implements Authenticatable {
                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthorizationUserId(): array
                {
                    return ['invalid'];
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            expect(fn () => $this->resolver->testResolveUserIdentifier($user))
                ->toThrow(InvalidArgumentException::class, 'getAuthorizationUserId() must return a string or numeric value');
        });

        it('falls back to getAuthIdentifier with user prefix', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(555);

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('user:555');
        });

        it('handles string auth identifier', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn('uuid-123-456');

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('user:uuid-123-456');
        });

        it('throws exception when getAuthIdentifier returns non-scalar', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(['invalid']);

            expect(fn () => $this->resolver->testResolveUserIdentifier($user))
                ->toThrow(InvalidArgumentException::class, 'User identifier must be scalar');
        });

        it('throws exception when getAuthIdentifier returns null', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(null);

            expect(fn () => $this->resolver->testResolveUserIdentifier($user))
                ->toThrow(InvalidArgumentException::class, 'User identifier must be scalar');
        });

        it('throws exception when getAuthIdentifier returns object', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(new stdClass);

            expect(fn () => $this->resolver->testResolveUserIdentifier($user))
                ->toThrow(InvalidArgumentException::class, 'User identifier must be scalar');
        });

        it('prioritizes authorizationUser over getAuthorizationUserId', function (): void {
            $user = new class implements Authenticatable, AuthorizationUser, AuthorizationUserId {
                public function authorizationUser(): string
                {
                    return 'priority:test:123';
                }

                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthorizationUserId(): string
                {
                    return 'should-not-use';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('priority:test:123');
        });

        it('prioritizes getAuthorizationUserId over getAuthIdentifier', function (): void {
            $user = new class implements Authenticatable, AuthorizationUserId {
                public function getAuthIdentifier(): mixed
                {
                    return 999; // Should not be used
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthorizationUserId(): string
                {
                    return 'custom:456';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('custom:456');
        });
    });

    describe('edge cases', function (): void {
        it('handles zero as valid identifier', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(0);

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('user:0');
        });

        it('handles empty string as identifier', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn('');

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('user:');
        });

        it('handles negative numbers', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(-123);

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('user:-123');
        });

        it('handles float identifiers', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123.456);

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('user:123.456');
        });

        it('handles very long identifiers', function (): void {
            $longId = str_repeat('a', 1000);
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn($longId);

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('user:' . $longId);
        });

        it('handles special characters in identifiers', function (): void {
            $specialId = 'user@example.com';
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn($specialId);

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('user:user@example.com');
        });
    });

    describe('interface detection', function (): void {
        it('properly detects method existence without interface', function (): void {
            $user = new class implements Authenticatable {
                // Method exists but class doesn't implement interface
                public function authorizationUser(): string
                {
                    return 'method:exists:123';
                }

                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('method:exists:123');
        });

        it('detects getAuthorizationUserId method without interface', function (): void {
            $user = new class implements Authenticatable {
                public function getAuthIdentifier(): mixed
                {
                    return 1;
                }

                public function getAuthIdentifierName(): string
                {
                    return 'id';
                }

                // Method exists but class doesn't implement interface
                public function getAuthorizationUserId(): string
                {
                    return 'method:exists:456';
                }

                public function getAuthPassword(): string
                {
                    return '';
                }

                public function getAuthPasswordName(): string
                {
                    return 'password';
                }

                public function getRememberToken(): string
                {
                    return '';
                }

                public function getRememberTokenName(): string
                {
                    return '';
                }

                public function setRememberToken($value): void
                {
                }
            };

            $result = $this->resolver->testResolveUserIdentifier($user);
            expect($result)->toBe('method:exists:456');
        });
    });
});
