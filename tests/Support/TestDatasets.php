<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use InvalidArgumentException;

/**
 * Provides readable, well-documented test datasets for common testing scenarios.
 */
final class TestDatasets
{
    /**
     * Cache behavior scenarios for testing caching functionality.
     */
    public static function cacheScenarios(): array
    {
        return [
            'cache hit with fresh data' => [
                'cache_enabled' => true,
                'data_exists' => true,
                'data_fresh' => true,
                'expected_cache_hit' => true,
                'expected_api_call' => false,
                'description' => 'Fresh cached data should be returned without API call',
            ],
            'cache miss with no data' => [
                'cache_enabled' => true,
                'data_exists' => false,
                'data_fresh' => false,
                'expected_cache_hit' => false,
                'expected_api_call' => true,
                'description' => 'Missing cache data should trigger API call and cache result',
            ],
            'cache hit with stale data' => [
                'cache_enabled' => true,
                'data_exists' => true,
                'data_fresh' => false,
                'expected_cache_hit' => false,
                'expected_api_call' => true,
                'description' => 'Stale cached data should trigger fresh API call',
            ],
            'cache disabled' => [
                'cache_enabled' => false,
                'data_exists' => true,
                'data_fresh' => true,
                'expected_cache_hit' => false,
                'expected_api_call' => true,
                'description' => 'Disabled cache should always trigger API calls',
            ],
        ];
    }

    /**
     * Configuration scenarios for testing different OpenFGA setups.
     */
    public static function configurationScenarios(): array
    {
        return [
            'minimal valid configuration' => [
                'config' => [
                    'url' => 'http://localhost:8080',
                    'store_id' => null,
                    'credentials' => ['method' => 'none'],
                ],
                'valid' => true,
                'description' => 'Basic configuration without authentication should work',
            ],
            'configuration with API token' => [
                'config' => [
                    'url' => 'https://api.openfga.example.com',
                    'store_id' => 'store-123',
                    'credentials' => [
                        'method' => 'api_token',
                        'token' => 'test-api-token-123',
                    ],
                ],
                'valid' => true,
                'description' => 'Configuration with API token authentication should work',
            ],
            'configuration with client credentials' => [
                'config' => [
                    'url' => 'https://api.openfga.example.com',
                    'store_id' => 'store-456',
                    'credentials' => [
                        'method' => 'client_credentials',
                        'client_id' => 'test-client-id',
                        'client_secret' => 'test-client-secret',
                        'api_audience' => 'https://api.openfga.example.com',
                        'api_issuer' => 'https://auth.example.com',
                    ],
                ],
                'valid' => true,
                'description' => 'Configuration with OAuth client credentials should work',
            ],
            'invalid URL configuration' => [
                'config' => [
                    'url' => 'not-a-valid-url',
                    'store_id' => null,
                    'credentials' => ['method' => 'none'],
                ],
                'valid' => false,
                'description' => 'Malformed URLs should be rejected during validation',
            ],
            'missing required client credentials' => [
                'config' => [
                    'url' => 'https://api.openfga.example.com',
                    'credentials' => [
                        'method' => 'client_credentials',
                        'client_id' => 'test-client-id',
                        // Missing client_secret, api_audience, api_issuer
                    ],
                ],
                'valid' => false,
                'description' => 'Incomplete client credentials should be rejected',
            ],
        ];
    }

    /**
     * Create a permission tuple with meaningful data.
     *
     * @param string $scenario
     * @param array  $overrides
     */
    public static function createPermissionTuple(
        string $scenario = 'default',
        array $overrides = [],
    ): array {
        $scenarios = [
            'default' => [
                'user' => TestConstants::DEFAULT_USER_ID,
                'relation' => TestConstants::RELATION_VIEWER,
                'object' => TestConstants::DEFAULT_DOCUMENT_ID,
            ],
            'admin_access' => [
                'user' => 'user:admin_alice',
                'relation' => TestConstants::RELATION_ADMIN,
                'object' => 'document:company_policy',
            ],
            'editor_access' => [
                'user' => 'user:editor_bob',
                'relation' => TestConstants::RELATION_EDITOR,
                'object' => 'document:draft_report',
            ],
            'denied_access' => [
                'user' => 'user:external_user',
                'relation' => TestConstants::RELATION_VIEWER,
                'object' => 'document:confidential_data',
            ],
        ];

        if (! isset($scenarios[$scenario])) {
            throw new InvalidArgumentException('Unknown permission scenario: ' . $scenario);
        }

        return array_merge($scenarios[$scenario], $overrides);
    }

    /**
     * Error condition scenarios for testing exception handling.
     */
    public static function errorScenarios(): array
    {
        return [
            'network timeout' => [
                'error_type' => 'timeout',
                'expected_exception' => 'OpenFGA\\Laravel\\Exceptions\\NetworkException',
                'retry_expected' => true,
                'description' => 'Network timeouts should trigger retry logic',
            ],
            'invalid credentials' => [
                'error_type' => 'authentication',
                'expected_exception' => 'OpenFGA\\Laravel\\Exceptions\\AuthenticationException',
                'retry_expected' => false,
                'description' => 'Authentication errors should fail immediately without retry',
            ],
            'malformed request' => [
                'error_type' => 'validation',
                'expected_exception' => 'OpenFGA\\Laravel\\Exceptions\\ValidationException',
                'retry_expected' => false,
                'description' => 'Request validation errors should fail immediately',
            ],
            'server error' => [
                'error_type' => 'server_error',
                'expected_exception' => 'OpenFGA\\Laravel\\Exceptions\\ServerException',
                'retry_expected' => true,
                'description' => 'Server errors should trigger retry logic',
            ],
        ];
    }

    /**
     * Get a specific dataset by name with error handling.
     *
     * @param string $datasetName
     */
    public static function getDataset(string $datasetName): array
    {
        $datasets = [
            'user_permissions' => self::userPermissionScenarios(),
            'configurations' => self::configurationScenarios(),
            'performance' => self::performanceScenarios(),
            'errors' => self::errorScenarios(),
            'cache' => self::cacheScenarios(),
            'integration' => self::integrationScenarios(),
        ];

        if (! isset($datasets[$datasetName])) {
            throw new InvalidArgumentException('Unknown dataset: ' . $datasetName);
        }

        return $datasets[$datasetName];
    }

    /**
     * Integration test scenarios representing real user workflows.
     */
    public static function integrationScenarios(): array
    {
        return [
            'document sharing workflow' => [
                'workflow' => [
                    'create_document' => ['user' => 'user:author', 'document' => 'document:my_report'],
                    'share_with_editor' => ['user' => 'user:editor', 'permission' => 'editor'],
                    'share_with_viewer' => ['user' => 'user:viewer', 'permission' => 'viewer'],
                    'verify_permissions' => true,
                ],
                'description' => 'Complete document sharing workflow from creation to permission verification',
            ],
            'organization hierarchy workflow' => [
                'workflow' => [
                    'create_organization' => ['org' => 'organization:tech_company'],
                    'add_admin' => ['user' => 'user:ceo', 'role' => 'admin'],
                    'add_members' => ['users' => ['user:developer1', 'user:developer2'], 'role' => 'member'],
                    'verify_hierarchy' => true,
                ],
                'description' => 'Organization setup with admin and member roles',
            ],
            'permission revocation workflow' => [
                'workflow' => [
                    'grant_permission' => ['user' => 'user:temp_contractor', 'permission' => 'viewer'],
                    'verify_access' => ['expected' => true],
                    'revoke_permission' => ['user' => 'user:temp_contractor', 'permission' => 'viewer'],
                    'verify_no_access' => ['expected' => false],
                ],
                'description' => 'Grant and then revoke permissions to verify proper cleanup',
            ],
        ];
    }

    /**
     * Performance test scenarios with realistic data sizes.
     */
    public static function performanceScenarios(): array
    {
        return [
            'small batch operation' => [
                'operation_count' => 10,
                'chunk_size' => 5,
                'expected_max_duration' => 100, // milliseconds
                'description' => 'Small batches should complete quickly',
            ],
            'medium batch operation' => [
                'operation_count' => 100,
                'chunk_size' => 25,
                'expected_max_duration' => 500, // milliseconds
                'description' => 'Medium batches should complete within reasonable time',
            ],
            'large batch operation' => [
                'operation_count' => 1000,
                'chunk_size' => 50,
                'expected_max_duration' => 2000, // milliseconds
                'description' => 'Large batches may take longer but should still be reasonable',
            ],
            'single permission check' => [
                'operation_count' => 1,
                'chunk_size' => 1,
                'expected_max_duration' => 50, // milliseconds
                'description' => 'Single permission checks should be very fast',
            ],
        ];
    }

    /**
     * User permission scenarios for testing authorization checks.
     *
     * Each scenario represents a common real-world permission case.
     */
    public static function userPermissionScenarios(): array
    {
        return [
            'admin with full access' => [
                'user' => 'user:admin_alice',
                'relation' => 'admin',
                'object' => 'document:company_policy',
                'expected' => true,
                'description' => 'Admin users should have full access to all documents',
            ],
            'editor with write access' => [
                'user' => 'user:editor_bob',
                'relation' => 'editor',
                'object' => 'document:draft_report',
                'expected' => true,
                'description' => 'Editors should be able to modify draft documents',
            ],
            'viewer with read only access' => [
                'user' => 'user:viewer_carol',
                'relation' => 'viewer',
                'object' => 'document:public_announcement',
                'expected' => true,
                'description' => 'Viewers should be able to read published documents',
            ],
            'unauthorized user denied access' => [
                'user' => 'user:external_dave',
                'relation' => 'viewer',
                'object' => 'document:confidential_data',
                'expected' => false,
                'description' => 'External users should not access confidential documents',
            ],
            'user requesting elevated permission' => [
                'user' => 'user:viewer_carol',
                'relation' => 'admin',
                'object' => 'document:public_announcement',
                'expected' => false,
                'description' => 'Viewers should not have admin rights even on accessible documents',
            ],
        ];
    }
}
