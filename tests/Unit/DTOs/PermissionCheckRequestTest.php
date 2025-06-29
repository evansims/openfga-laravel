<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use OpenFGA\Laravel\DTOs\PermissionCheckRequest;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('PermissionCheckRequest', function (): void {
    it('can be constructed with required parameters', function (): void {
        $request = new PermissionCheckRequest(
            userId: 'user:123',
            relation: 'viewer',
            object: 'document:456',
        );

        expect($request->userId)->toBe('user:123');
        expect($request->relation)->toBe('viewer');
        expect($request->object)->toBe('document:456');
        expect($request->context)->toBe([]);
        expect($request->contextualTuples)->toBe([]);
        expect($request->connection)->toBeNull();
        expect($request->cached)->toBeFalse();
        expect($request->duration)->toBeNull();
    });

    it('can be constructed with all parameters', function (): void {
        $context = ['ip_address' => '192.168.1.1'];
        $contextualTuples = ['user:admin', 'admin', 'organization:1'];

        $request = new PermissionCheckRequest(
            userId: 'user:123',
            relation: 'editor',
            object: 'document:456',
            context: $context,
            contextualTuples: $contextualTuples,
            connection: 'test_connection',
            cached: true,
            duration: 0.123,
        );

        expect($request->userId)->toBe('user:123');
        expect($request->relation)->toBe('editor');
        expect($request->object)->toBe('document:456');
        expect($request->context)->toBe($context);
        expect($request->contextualTuples)->toBe($contextualTuples);
        expect($request->connection)->toBe('test_connection');
        expect($request->cached)->toBeTrue();
        expect($request->duration)->toBe(0.123);
    });

    it('can be created from authenticatable user', function (): void {
        $user = new class implements Authenticatable {
            public function getAuthIdentifier()
            {
                return 789;
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthPassword(): string
            {
                return 'secret';
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
                // No-op
            }
        };

        $request = PermissionCheckRequest::fromUser(
            user: $user,
            relation: 'viewer',
            object: 'document:123',
        );

        expect($request->userId)->toBe('user:789');
        expect($request->relation)->toBe('viewer');
        expect($request->object)->toBe('document:123');
    });

    it('can be created from user with custom authorization method', function (): void {
        $user = new class implements Authenticatable {
            public function authorizationUser(): string
            {
                return 'custom:user-456';
            }

            public function getAuthIdentifier()
            {
                return 456;
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthPassword(): string
            {
                return 'secret';
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
                // No-op
            }
        };

        $request = PermissionCheckRequest::fromUser(
            user: $user,
            relation: 'editor',
            object: 'document:789',
        );

        expect($request->userId)->toBe('custom:user-456');
    });

    it('can be created from user with getAuthorizationUserId method', function (): void {
        $user = new class implements Authenticatable {
            public function getAuthIdentifier()
            {
                return 789;
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthorizationUserId(): string
            {
                return 'method:user-789';
            }

            public function getAuthPassword(): string
            {
                return 'secret';
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
                // No-op
            }
        };

        $request = PermissionCheckRequest::fromUser(
            user: $user,
            relation: 'admin',
            object: 'organization:100',
        );

        expect($request->userId)->toBe('method:user-789');
    });

    it('falls back to unknown user for invalid auth identifier', function (): void {
        $user = new class implements Authenticatable {
            public function getAuthIdentifier()
            {
                return null;
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthPassword(): string
            {
                return 'secret';
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
                // No-op
            }
        };

        $request = PermissionCheckRequest::fromUser(
            user: $user,
            relation: 'viewer',
            object: 'document:123',
        );

        expect($request->userId)->toBe('user:unknown');
    });

    it('converts to array correctly', function (): void {
        $request = new PermissionCheckRequest(
            userId: 'user:123',
            relation: 'viewer',
            object: 'document:456',
            context: ['key' => 'value'],
            contextualTuples: ['tuple1', 'tuple2'],
            connection: 'test_connection',
            cached: true,
            duration: 0.456,
        );

        $array = $request->toArray();

        expect($array)->toBe([
            'user' => 'user:123',
            'relation' => 'viewer',
            'object' => 'document:456',
            'context' => ['key' => 'value'],
            'contextual_tuples' => ['tuple1', 'tuple2'],
            'connection' => 'test_connection',
            'cached' => true,
            'duration' => 0.456,
        ]);
    });

    it('converts to string correctly', function (): void {
        $request = new PermissionCheckRequest(
            userId: 'user:123',
            relation: 'editor',
            object: 'document:789',
        );

        $string = $request->toString();

        expect($string)->toBe('user:123 can editor on document:789');
    });

    it('can create cached version', function (): void {
        $original = new PermissionCheckRequest(
            userId: 'user:123',
            relation: 'viewer',
            object: 'document:456',
        );

        $cached = $original->withCached(true, 0.123);

        expect($cached->userId)->toBe('user:123');
        expect($cached->relation)->toBe('viewer');
        expect($cached->object)->toBe('document:456');
        expect($cached->cached)->toBeTrue();
        expect($cached->duration)->toBe(0.123);

        // Original should be unchanged
        expect($original->cached)->toBeFalse();
        expect($original->duration)->toBeNull();
    });

    it('can create cached version without duration', function (): void {
        $original = new PermissionCheckRequest(
            userId: 'user:123',
            relation: 'viewer',
            object: 'document:456',
        );

        $cached = $original->withCached(true);

        expect($cached->cached)->toBeTrue();
        expect($cached->duration)->toBeNull();
    });

    it('preserves all properties when creating cached version', function (): void {
        $original = new PermissionCheckRequest(
            userId: 'user:123',
            relation: 'editor',
            object: 'document:456',
            context: ['ip' => '127.0.0.1'],
            contextualTuples: ['admin_tuple'],
            connection: 'custom_connection',
        );

        $cached = $original->withCached(true, 0.789);

        expect($cached->userId)->toBe($original->userId);
        expect($cached->relation)->toBe($original->relation);
        expect($cached->object)->toBe($original->object);
        expect($cached->context)->toBe($original->context);
        expect($cached->contextualTuples)->toBe($original->contextualTuples);
        expect($cached->connection)->toBe($original->connection);
        expect($cached->cached)->toBeTrue();
        expect($cached->duration)->toBe(0.789);
    });

    it('handles string auth identifier', function (): void {
        $user = new class implements Authenticatable {
            public function getAuthIdentifier()
            {
                return 'abc-123-def';
            }

            public function getAuthIdentifierName(): string
            {
                return 'uuid';
            }

            public function getAuthPassword(): string
            {
                return 'secret';
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
                // No-op
            }
        };

        $request = PermissionCheckRequest::fromUser(
            user: $user,
            relation: 'viewer',
            object: 'document:123',
        );

        expect($request->userId)->toBe('user:abc-123-def');
    });

    it('handles non-string non-numeric auth identifier gracefully', function (): void {
        $user = new class implements Authenticatable {
            public function getAuthIdentifier()
            {
                return ['invalid'];
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthPassword(): string
            {
                return 'secret';
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }

            public function setRememberToken($value): void
            {
                // No-op
            }
        };

        $request = PermissionCheckRequest::fromUser(
            user: $user,
            relation: 'viewer',
            object: 'document:123',
        );

        expect($request->userId)->toBe('user:unknown');
    });
});
