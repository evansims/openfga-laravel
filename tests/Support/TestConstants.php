<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

/**
 * Centralized test constants to avoid hard-coded values throughout tests.
 */
final class TestConstants
{
    public const ALTERNATIVE_API_URL = 'https://api.test.example.com';

    public const ALTERNATIVE_DOCUMENT_ID = 'document:888';

    public const ALTERNATIVE_MODEL_ID = '01HWQR5W9QKT9K8Y7VPGQGF000';

    public const ALTERNATIVE_ORGANIZATION_ID = 'organization:777';

    public const ALTERNATIVE_STORE_ID = '01HWQR5W9QKT9K8Y7VPGQGF789';

    public const ALTERNATIVE_TIMESTAMP = 1234567900;

    // Alternative test identifiers
    public const ALTERNATIVE_USER_ID = 'user:999';

    public const ALTERNATIVE_USER_PREFIX = 'account:';

    // Test URLs
    public const DEFAULT_API_URL = 'http://localhost:8080';

    // Batch sizes
    public const DEFAULT_BATCH_SIZE = 100;

    // Cache settings
    public const DEFAULT_CACHE_TTL = 300;

    public const DEFAULT_DOCUMENT_ID = 'document:456';

    // Connection pool settings
    public const DEFAULT_MAX_CONNECTIONS = 10;

    // Retry settings
    public const DEFAULT_MAX_RETRIES = 3;

    public const DEFAULT_MIN_CONNECTIONS = 2;

    public const DEFAULT_ORGANIZATION_ID = 'organization:789';

    // Queue settings
    public const DEFAULT_QUEUE_CONNECTION = 'default';

    public const DEFAULT_RETRY_DELAY = 1000;

    // Timeout values
    public const DEFAULT_TIMEOUT = 10;

    // Common test identifiers
    public const DEFAULT_USER_ID = 'user:123';

    // User prefixes
    public const DEFAULT_USER_PREFIX = 'user:';

    public const EMPTY_USER_PREFIX = '';

    public const ERROR_CONNECTION_FAILED = 'Connection failed';

    // Error messages (for consistent error testing)
    public const ERROR_INVALID_CONFIG = 'Invalid configuration';

    public const ERROR_NOT_FOUND = 'Resource not found';

    public const ERROR_TIMEOUT = 'Timeout waiting for available connection';

    public const ERROR_UNAUTHORIZED = 'Unauthorized';

    // Test timestamps (fixed for deterministic testing)
    public const FIXED_TIMESTAMP = 1234567890;

    public const INVALID_API_URL = 'invalid-url';

    public const LARGE_BATCH_SIZE = 500;

    public const LARGE_CONNECTION_POOL = 20;

    public const LARGE_DATASET_SIZE = 1000;

    public const LONG_CACHE_TTL = 3600;

    public const LONG_TIMEOUT = 30;

    public const MANY_RETRIES = 10;

    public const NO_RETRIES = 0;

    // Performance test values
    public const PERFORMANCE_ITERATIONS = 100;

    public const QUEUE_NAME = 'openfga';

    public const RELATION_ADMIN = 'admin';

    public const RELATION_EDITOR = 'editor';

    public const RELATION_MEMBER = 'member';

    // Relations
    public const RELATION_OWNER = 'owner';

    public const RELATION_VIEWER = 'viewer';

    public const SHORT_CACHE_TTL = 60;

    public const SHORT_TIMEOUT = 1;

    public const SMALL_BATCH_SIZE = 10;

    public const SMALL_CONNECTION_POOL = 2;

    public const SMALL_DATASET_SIZE = 10;

    // Test tokens and credentials
    public const TEST_API_TOKEN = 'test-api-token-12345';

    public const TEST_AUDIENCE = 'https://api.test.example.com';

    public const TEST_CLIENT_ID = 'test-client-id';

    public const TEST_CLIENT_SECRET = 'test-client-secret';

    // File paths (relative to tests directory)
    public const TEST_CONFIG_FILE = 'fixtures/config.json';

    public const TEST_MODEL_FILE = 'fixtures/model.json';

    public const TEST_MODEL_ID = '01HWQR5W9QKT9K8Y7VPGQGF456';

    public const TEST_PERMISSIONS_FILE = 'fixtures/permissions.csv';

    public const TEST_QUEUE_CONNECTION = 'test';

    // Store and model IDs
    public const TEST_STORE_ID = '01HWQR5W9QKT9K8Y7VPGQGF123';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_ORGANIZATION = 'organization';

    // Object types
    public const TYPE_USER = 'user';

    /**
     * Get a sequential set of document IDs.
     *
     * @param int $count
     */
    public static function sequentialDocumentIds(int $count = 3): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $ids[] = "document:{$i}";
        }

        return $ids;
    }

    /**
     * Get a sequential set of user IDs.
     *
     * @param int $count
     */
    public static function sequentialUserIds(int $count = 3): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $ids[] = "user:{$i}";
        }

        return $ids;
    }

    /**
     * Get a test document ID with optional suffix.
     *
     * @param string $suffix
     */
    public static function testDocumentId(string $suffix = ''): string
    {
        return $suffix ? "document:{$suffix}" : self::DEFAULT_DOCUMENT_ID;
    }

    /**
     * Get a test organization ID with optional suffix.
     *
     * @param string $suffix
     */
    public static function testOrganizationId(string $suffix = ''): string
    {
        return $suffix ? "organization:{$suffix}" : self::DEFAULT_ORGANIZATION_ID;
    }

    /**
     * Get a test user ID with optional suffix.
     *
     * @param string $suffix
     */
    public static function testUserId(string $suffix = ''): string
    {
        return $suffix ? "user:{$suffix}" : self::DEFAULT_USER_ID;
    }

    /**
     * Get a unique identifier with optional prefix.
     *
     * @param string $type
     * @param string $prefix
     */
    public static function uniqueId(string $type = 'test', string $prefix = ''): string
    {
        $suffix = time() . '_' . random_int(1000, 9999);

        return $prefix ? "{$prefix}:{$type}_{$suffix}" : "{$type}_{$suffix}";
    }
}
