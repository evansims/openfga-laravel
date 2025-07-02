<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Mockery;
use Mockery\MockInterface;
use OpenFGA\ClientInterface;
use OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager;
use OpenFGA\Laravel\Contracts\{AuthorizationObject, AuthorizationUser};
use stdClass;

use function sprintf;

/**
 * Factory for creating test objects consistently across the test suite.
 */
final class TestFactories
{
    /**
     * Create test authorization model data.
     *
     * @param array $customTypes
     */
    public static function createAuthorizationModel(array $customTypes = []): array
    {
        $defaultTypes = [
            [
                'type' => 'user',
            ],
            [
                'type' => 'document',
                'relations' => [
                    'owner' => ['this' => new stdClass],
                    'editor' => [
                        'this' => new stdClass,
                        'computed_userset' => ['object' => '', 'relation' => 'owner'],
                    ],
                    'viewer' => [
                        'this' => new stdClass,
                        'computed_userset' => ['object' => '', 'relation' => 'editor'],
                    ],
                ],
            ],
            [
                'type' => 'organization',
                'relations' => [
                    'admin' => ['this' => new stdClass],
                    'member' => ['this' => new stdClass],
                ],
            ],
        ];

        return [
            'schema_version' => '1.1',
            'type_definitions' => [] === $customTypes ? $defaultTypes : $customTypes,
        ];
    }

    /**
     * Create a mock OpenFGA client with common setup.
     */
    public static function createMockClient(): MockInterface
    {
        return Mockery::mock(ClientInterface::class);
    }

    /**
     * Create a mock OpenFGA manager with common setup.
     */
    public static function createMockManager(): MockInterface
    {
        return Mockery::mock(AbstractOpenFgaManager::class);
    }

    /**
     * Create permission tuple data for testing.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     */
    public static function createPermissionTuple(
        string $user = 'user:123',
        string $relation = 'viewer',
        string $object = 'document:456',
    ): array {
        return [
            'user' => $user,
            'relation' => $relation,
            'object' => $object,
        ];
    }

    /**
     * Create multiple permission tuples for testing.
     *
     * @param int    $count
     * @param string $prefix
     */
    public static function createPermissionTuples(int $count = 3, string $prefix = ''): array
    {
        $tuples = [];

        for ($i = 1; $i <= $count; ++$i) {
            $suffix = '' !== $prefix && '0' !== $prefix ? sprintf('_%s_%d', $prefix, $i) : '_' . $i;
            $tuples[] = self::createPermissionTuple(
                user: 'user' . $suffix,
                relation: 'viewer',
                object: 'document' . $suffix,
            );
        }

        return $tuples;
    }

    /**
     * Create a test document model implementing authorization interfaces.
     *
     * @param string $objectId
     * @param mixed  $identifier
     * @param array  $customAttributes
     */
    public static function createTestDocument(
        string $objectId = 'document:456',
        mixed $identifier = 456,
        array $customAttributes = [],
    ): object {
        $class = new class extends Model implements AuthorizationObject {
            protected $table = 'documents';

            public $timestamps = false;

            private string $testObjectId = 'document:456';

            private mixed $testIdentifier = 456;

            private array $testAttributes = [];

            public function authorizationObject(): string
            {
                return $this->testObjectId;
            }

            public function getKey(): mixed
            {
                return $this->testIdentifier;
            }

            public function getAttribute($key)
            {
                return $this->testAttributes[$key] ?? parent::getAttribute($key);
            }

            public function setTestData(string $objectId, mixed $identifier, array $attributes): void
            {
                $this->testObjectId = $objectId;
                $this->testIdentifier = $identifier;
                $this->testAttributes = $attributes;
            }
        };

        $class->setTestData($objectId, $identifier, $customAttributes);

        return $class;
    }

    /**
     * Create a test organization model.
     *
     * @param string $objectId
     * @param mixed  $identifier
     * @param array  $customAttributes
     */
    public static function createTestOrganization(
        string $objectId = 'organization:789',
        mixed $identifier = 789,
        array $customAttributes = [],
    ): object {
        $class = new class extends Model implements AuthorizationObject {
            protected $table = 'organizations';

            public $timestamps = false;

            private string $testObjectId = 'organization:789';

            private mixed $testIdentifier = 789;

            private array $testAttributes = [];

            public function authorizationObject(): string
            {
                return $this->testObjectId;
            }

            public function getKey(): mixed
            {
                return $this->testIdentifier;
            }

            public function getAttribute($key)
            {
                return $this->testAttributes[$key] ?? parent::getAttribute($key);
            }

            public function setTestData(string $objectId, mixed $identifier, array $attributes): void
            {
                $this->testObjectId = $objectId;
                $this->testIdentifier = $identifier;
                $this->testAttributes = $attributes;
            }
        };

        $class->setTestData($objectId, $identifier, $customAttributes);

        return $class;
    }

    /**
     * Create a test user implementing required authorization interfaces.
     *
     * @param string $authId
     * @param mixed  $identifier
     * @param array  $customAttributes
     */
    public static function createTestUser(
        string $authId = 'user:123',
        mixed $identifier = 123,
        array $customAttributes = [],
    ): object {
        return new readonly class($authId, $identifier, $customAttributes) implements Authenticatable, AuthorizationUser {
            public function __construct(
                private string $authId,
                private mixed $identifier,
                private array $customAttributes = [],
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
                // No-op for test
            }

            public function getAttribute($key)
            {
                return $this->customAttributes[$key] ?? null;
            }
        };
    }

    /**
     * Create a test model with both user and object interfaces.
     *
     * @param string $authId
     * @param string $objectId
     * @param mixed  $identifier
     * @param array  $customAttributes
     */
    public static function createTestUserWithObject(
        string $authId = 'user:123',
        string $objectId = 'user:123',
        mixed $identifier = 123,
        array $customAttributes = [],
    ): object {
        $class = new class extends Model implements Authenticatable, AuthorizationObject, AuthorizationUser {
            protected $table = 'users';

            public $timestamps = false;

            private string $testAuthId = 'user:123';

            private string $testObjectId = 'user:123';

            private mixed $testIdentifier = 123;

            private array $testAttributes = [];

            public function authorizationUser(): string
            {
                return $this->testAuthId;
            }

            public function authorizationObject(): string
            {
                return $this->testObjectId;
            }

            public function getAuthIdentifier(): mixed
            {
                return $this->testIdentifier;
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
                // No-op for test
            }

            public function getKey(): mixed
            {
                return $this->testIdentifier;
            }

            public function getAttribute($key)
            {
                return $this->testAttributes[$key] ?? parent::getAttribute($key);
            }

            public function setTestData(string $authId, string $objectId, mixed $identifier, array $attributes): void
            {
                $this->testAuthId = $authId;
                $this->testObjectId = $objectId;
                $this->testIdentifier = $identifier;
                $this->testAttributes = $attributes;
            }
        };

        $class->setTestData($authId, $objectId, $identifier, $customAttributes);

        return $class;
    }
}
