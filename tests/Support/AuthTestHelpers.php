<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use OpenFGA\Laravel\Contracts\{AuthorizationObject, AuthorizationUser};

// Helper function to create test user
function createAuthUser(string $authId, mixed $identifier = 123): object
{
    return new class($authId, $identifier) extends Model implements Authenticatable, AuthorizationUser {
        public function __construct(
            private string $authId,
            private mixed $identifier,
        ) {
        }

        public function authorizationUser(): string
        {
            return $this->authId;
        }

        public function getAuthIdentifier(): mixed
        {
            return $this->identifier;
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

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function getRememberTokenName(): string
        {
            return '';
        }

        public function setRememberToken($value): void
        {
        }
    };
}

// Helper function to create test authorization object
function createAuthObject(string $objectId): object
{
    return new class($objectId) extends Model implements AuthorizationObject {
        public function __construct(
            private string $objectId,
        ) {
        }

        public function authorizationObject(): string
        {
            return $this->objectId;
        }
    };
}

// Helper function to create non-AuthorizationUser authenticatable
function createRegularAuthenticatable(mixed $identifier = 456): object
{
    return new class($identifier) extends Model implements Authenticatable {
        public function __construct(
            private mixed $identifier,
        ) {
        }

        public function getAuthIdentifier(): mixed
        {
            return $this->identifier;
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

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function getRememberTokenName(): string
        {
            return '';
        }

        public function setRememberToken($value): void
        {
        }
    };
}

// Helper function to create plain model
function createPlainModel(string $table, mixed $key): object
{
    return new class($table, $key) extends Model {
        public function __construct(
            private string $tableName,
            private mixed $keyValue,
        ) {
        }

        public function getKey(): mixed
        {
            return $this->keyValue;
        }

        public function getTable(): string
        {
            return $this->tableName;
        }
    };
}
