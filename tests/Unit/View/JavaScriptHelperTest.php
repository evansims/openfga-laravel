<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\View\JavaScriptHelper;

uses(TestCase::class);

describe('JavaScriptHelper', function (): void {
    beforeEach(function (): void {
        // Mock the manager interface instead of the concrete class
        $this->manager = Mockery::mock(ManagerInterface::class);
        $this->helper = new JavaScriptHelper($this->manager);
    });

    it('is marked as final and readonly', function (): void {
        $reflection = new ReflectionClass(JavaScriptHelper::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    describe('generateHelperFunctions', function (): void {
        it('generates complete JavaScript helper functions', function (): void {
            $js = $this->helper->generateHelperFunctions();

            expect($js)->toContain('window.OpenFGA = window.OpenFGA || {};');
            expect($js)->toContain('window.OpenFGA.can = function(relation, object)');
            expect($js)->toContain('window.OpenFGA.cannot = function(relation, object)');
            expect($js)->toContain('window.OpenFGA.canAny = function(relations, object)');
            expect($js)->toContain('window.OpenFGA.canAll = function(relations, object)');
            expect($js)->toContain('window.OpenFGA.getUser = function()');
            expect($js)->toContain('window.OpenFGA.isAuthenticated = function()');
            expect($js)->toContain('window.OpenFGA.toggleByPermission = function(element, relation, object, showIfTrue = true)');
            expect($js)->toContain('window.OpenFGA.toggleEnabledByPermission = function(element, relation, object, enableIfTrue = true)');
        });
    });

    describe('generatePermissionsScript', function (): void {
        it('returns empty permissions when user is not authenticated', function (): void {
            Auth::shouldReceive('check')->once()->andReturnFalse();

            $script = $this->helper->generatePermissionsScript(['doc:123'], ['read']);

            expect($script)->toBe('window.OpenFGA = { permissions: {}, user: null };');
        });

        it('returns empty permissions when Auth::user() returns null', function (): void {
            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->once()->andReturnNull();

            $script = $this->helper->generatePermissionsScript(['doc:123'], ['read']);

            expect($script)->toBe('window.OpenFGA = { permissions: {}, user: null };');
        });

        it('generates permissions script for authenticated user', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturnTrue();

            $this->manager->shouldReceive('check')
                ->with('user:123', 'write', 'doc:123', [], [], null)
                ->once()
                ->andReturnFalse();

            $script = $this->helper->generatePermissionsScript(['doc:123'], ['read', 'write']);

            expect($script)->toContain('window.OpenFGA = {');
            expect($script)->toContain('"permissions": {');
            expect($script)->toContain('"doc:123": {');
            expect($script)->toContain('"read": true');
            expect($script)->toContain('"write": false');
            expect($script)->toContain('"user": {');
            expect($script)->toContain('"id": "user:123"');
            expect($script)->toContain('"auth_id": 123');
        });

        it('handles multiple objects and relations', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(456);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->andReturn($user);

            // Four permission checks: 2 objects × 2 relations
            $this->manager->shouldReceive('check')
                ->with('user:456', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturnTrue();

            $this->manager->shouldReceive('check')
                ->with('user:456', 'write', 'doc:123', [], [], null)
                ->once()
                ->andReturnFalse();

            $this->manager->shouldReceive('check')
                ->with('user:456', 'read', 'doc:456', [], [], null)
                ->once()
                ->andReturnFalse();

            $this->manager->shouldReceive('check')
                ->with('user:456', 'write', 'doc:456', [], [], null)
                ->once()
                ->andReturnTrue();

            $script = $this->helper->generatePermissionsScript(['doc:123', 'doc:456'], ['read', 'write']);

            expect($script)->toContain('"doc:123": {');
            expect($script)->toContain('"doc:456": {');
            expect($script)->toContain('"read": true');
            expect($script)->toContain('"write": false');
        });

        it('uses custom connection when provided', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(789);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:789', 'read', 'doc:123', [], [], 'secondary')
                ->once()
                ->andReturnTrue();

            $script = $this->helper->generatePermissionsScript(['doc:123'], ['read'], 'secondary');

            expect($script)->toContain('"read": true');
        });

        it('handles user with authorizationUser method', function (): void {
            $user = new class implements Authenticatable {
                public function authorizationUser(): string
                {
                    return 'custom:user:999';
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

            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('custom:user:999', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturnTrue();

            $script = $this->helper->generatePermissionsScript(['doc:123'], ['read']);

            expect($script)->toContain('"id": "custom:user:999"');
            expect($script)->toContain('"auth_id": 1');
        });

        it('returns fallback script on JSON encoding error', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn("\xB1\x31"); // Invalid UTF-8

            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->andReturn($user);

            $this->manager->shouldReceive('check')
                ->once()
                ->andReturnTrue();

            $script = $this->helper->generatePermissionsScript(['doc:123'], ['read']);

            expect($script)->toBe('window.OpenFGA = {};');
        });
    });

    describe('generate', function (): void {
        it('generates helper functions only when no objects or relations provided', function (): void {
            $script = $this->helper->generate();

            expect($script)->toContain('window.OpenFGA.can = function(relation, object)');
            expect($script)->not()->toContain('window.OpenFGA = {');
        });

        it('generates helper functions only when objects are empty', function (): void {
            $script = $this->helper->generate([], ['read']);

            expect($script)->toContain('window.OpenFGA.can = function(relation, object)');
            expect($script)->not()->toContain('"permissions":');
        });

        it('generates helper functions only when relations are empty', function (): void {
            $script = $this->helper->generate(['doc:123'], []);

            expect($script)->toContain('window.OpenFGA.can = function(relation, object)');
            expect($script)->not()->toContain('"permissions":');
        });

        it('generates complete script when objects and relations are provided', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->andReturn($user);

            $this->manager->shouldReceive('check')
                ->once()
                ->andReturnTrue();

            $script = $this->helper->generate(['doc:123'], ['read']);

            expect($script)->toContain('window.OpenFGA.can = function(relation, object)');
            expect($script)->toContain('window.OpenFGA = {');
            expect($script)->toContain('"permissions":');
        });

        it('passes connection parameter to permissions script', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('user:123', 'read', 'doc:123', [], [], 'custom')
                ->once()
                ->andReturnTrue();

            $script = $this->helper->generate(['doc:123'], ['read'], 'custom');

            expect($script)->toContain('"permissions":');
        });
    });

    describe('bladeDirective', function (): void {
        it('wraps generated script in script tags', function (): void {
            $html = $this->helper->bladeDirective();

            expect($html)->toStartWith('<script>');
            expect($html)->toEndWith('</script>');
            expect($html)->toContain('window.OpenFGA.can = function(relation, object)');
        });

        it('includes permissions when objects and relations are provided', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(123);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->andReturn($user);

            $this->manager->shouldReceive('check')
                ->once()
                ->andReturnTrue();

            $html = $this->helper->bladeDirective(['doc:123'], ['read']);

            expect($html)->toStartWith('<script>');
            expect($html)->toEndWith('</script>');
            expect($html)->toContain('"permissions":');
        });
    });

    describe('user resolution edge cases', function (): void {
        it('handles user with getAuthorizationUserId method', function (): void {
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
                    return 555;
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

            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->andReturn($user);

            $this->manager->shouldReceive('check')
                ->with('555', 'read', 'doc:123', [], [], null)
                ->once()
                ->andReturnTrue();

            $script = $this->helper->generatePermissionsScript(['doc:123'], ['read']);

            expect($script)->toContain('"id": "555"');
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

            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->once()->andReturn($user);

            expect(fn () => $this->helper->generatePermissionsScript(['doc:123'], ['read']))
                ->toThrow(InvalidArgumentException::class, 'authorizationUser() must return a string or numeric value');
        });

        it('throws exception when getAuthIdentifier returns non-scalar', function (): void {
            $user = Mockery::mock(Authenticatable::class);
            $user->shouldReceive('getAuthIdentifier')->andReturn(['invalid']);

            Auth::shouldReceive('check')->once()->andReturnTrue();
            Auth::shouldReceive('user')->once()->andReturn($user);

            expect(fn () => $this->helper->generatePermissionsScript(['doc:123'], ['read']))
                ->toThrow(InvalidArgumentException::class, 'User identifier must be scalar');
        });
    });
});
