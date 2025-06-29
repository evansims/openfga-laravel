<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Traits;

use OpenFGA\Laravel\Traits\ManagerOperations;
use OpenFGA\Models\TupleKey;

describe('ManagerOperations Trait', function (): void {
    beforeEach(function (): void {
        // Create a test class that uses the trait
        $this->manager = new class {
            use ManagerOperations;
        };
    });

    describe('normalizeContextualTuples', function (): void {
        it('normalizes array tuples to TupleKey instances', function (): void {
            $tuples = [
                ['user' => 'user:123', 'relation' => 'read', 'object' => 'doc:456'],
                ['user' => 'user:789', 'relation' => 'write', 'object' => 'doc:111'],
            ];

            $result = $this->manager->normalizeContextualTuples($tuples);

            expect($result)->toHaveCount(2);
            expect($result->count())->toBe(2);
        });

        it('handles existing TupleKey instances', function (): void {
            $tuples = [
                new TupleKey('user:123', 'read', 'doc:456'),
                ['user' => 'user:789', 'relation' => 'write', 'object' => 'doc:111'],
            ];

            $result = $this->manager->normalizeContextualTuples($tuples);

            expect($result)->toHaveCount(2);
        });

        it('handles empty arrays', function (): void {
            $result = $this->manager->normalizeContextualTuples([]);

            expect($result)->toHaveCount(0);
        });

        it('handles missing array keys gracefully', function (): void {
            $tuples = [
                ['user' => 'user:123'], // Missing relation and object
                ['relation' => 'read'], // Missing user and object
                [], // Empty array
            ];

            $result = $this->manager->normalizeContextualTuples($tuples);

            expect($result)->toHaveCount(3);
        });
    });

    describe('buildCacheKey', function (): void {
        it('builds consistent cache keys', function (): void {
            $params = ['user' => 'user:123', 'relation' => 'read', 'object' => 'doc:456'];

            $key1 = $this->manager->buildCacheKey('check', $params);
            $key2 = $this->manager->buildCacheKey('check', $params);

            expect($key1)->toBe($key2);
            expect($key1)->toStartWith('openfga:check:');
        });

        it('sorts params for consistency', function (): void {
            $params1 = ['b' => '2', 'a' => '1', 'c' => '3'];
            $params2 = ['c' => '3', 'a' => '1', 'b' => '2'];

            $key1 = $this->manager->buildCacheKey('check', $params1);
            $key2 = $this->manager->buildCacheKey('check', $params2);

            expect($key1)->toBe($key2);
        });

        it('handles different operations', function (): void {
            $params = ['user' => 'user:123'];

            $checkKey = $this->manager->buildCacheKey('check', $params);
            $listKey = $this->manager->buildCacheKey('list', $params);

            expect($checkKey)->not->toBe($listKey);
            expect($checkKey)->toContain('check');
            expect($listKey)->toContain('list');
        });
    });

    describe('parseConnectionConfig', function (): void {
        it('parses complete configuration', function (): void {
            $config = [
                'url' => 'https://api.example.com',
                'store_id' => 'store-123',
                'authorization_model_id' => 'model-456',
                'credentials' => ['method' => 'api_token', 'token' => 'secret'],
            ];

            $parsed = $this->manager->parseConnectionConfig($config);

            expect($parsed)->toBe([
                'url' => 'https://api.example.com',
                'store_id' => 'store-123',
                'authorization_model_id' => 'model-456',
                'credentials' => ['method' => 'api_token', 'token' => 'secret'],
            ]);
        });

        it('provides defaults for missing values', function (): void {
            $config = [];

            $parsed = $this->manager->parseConnectionConfig($config);

            expect($parsed)->toBe([
                'url' => '',
                'store_id' => '',
                'authorization_model_id' => null,
                'credentials' => [],
            ]);
        });
    });

    describe('validateConnectionConfig', function (): void {
        it('validates required fields', function (): void {
            $config = [];

            $errors = $this->manager->validateConnectionConfig($config);

            expect($errors)->toContain('URL is required');
            expect($errors)->toContain('Store ID is required');
        });

        it('validates URL format', function (): void {
            $config = [
                'url' => 'not-a-url',
                'store_id' => 'store-123',
            ];

            $errors = $this->manager->validateConnectionConfig($config);

            expect($errors)->toContain('URL must be a valid URL');
        });

        it('accepts valid configuration', function (): void {
            $config = [
                'url' => 'https://api.example.com',
                'store_id' => 'store-123',
                'credentials' => ['method' => 'none'],
            ];

            $errors = $this->manager->validateConnectionConfig($config);

            expect($errors)->toBeEmpty();
        });
    });

    describe('validateCredentials', function (): void {
        it('validates api_token method', function (): void {
            $credentials = ['method' => 'api_token'];

            $errors = $this->manager->validateCredentials($credentials);

            expect($errors)->toContain('API token is required when using api_token authentication');
        });

        it('validates client_credentials method', function (): void {
            $credentials = ['method' => 'client_credentials'];

            $errors = $this->manager->validateCredentials($credentials);

            expect($errors)->toContain('Client ID is required for client credentials');
            expect($errors)->toContain('Client secret is required for client credentials');
            expect($errors)->toContain('Either token_endpoint or api_token_issuer is required for client credentials');
        });

        it('accepts valid api_token credentials', function (): void {
            $credentials = [
                'method' => 'api_token',
                'token' => 'secret-token',
            ];

            $errors = $this->manager->validateCredentials($credentials);

            expect($errors)->toBeEmpty();
        });

        it('accepts valid client_credentials', function (): void {
            $credentials = [
                'method' => 'client_credentials',
                'client_id' => 'client-123',
                'client_secret' => 'secret',
                'token_endpoint' => 'https://auth.example.com/token',
            ];

            $errors = $this->manager->validateCredentials($credentials);

            expect($errors)->toBeEmpty();
        });

        it('rejects unknown methods', function (): void {
            $credentials = ['method' => 'unknown'];

            $errors = $this->manager->validateCredentials($credentials);

            expect($errors)->toContain('Unknown authentication method: unknown');
        });
    });

    describe('extractObjectIds', function (): void {
        it('extracts IDs from object strings', function (): void {
            $objects = [
                'document:123',
                'folder:456',
                'file:789',
            ];

            $ids = $this->manager->extractObjectIds($objects);

            expect($ids)->toBe(['123', '456', '789']);
        });

        it('handles objects without colons', function (): void {
            $objects = [
                'document:123',
                'invalid',
                'folder:456',
            ];

            $ids = $this->manager->extractObjectIds($objects);

            expect($ids)->toBe(['123', '456']);
        });

        it('handles empty array', function (): void {
            $ids = $this->manager->extractObjectIds([]);

            expect($ids)->toBeEmpty();
        });

        it('handles objects with multiple colons', function (): void {
            $objects = [
                'document:123:version:1',
                'folder:456',
            ];

            $ids = $this->manager->extractObjectIds($objects);

            expect($ids)->toBe(['123:version:1', '456']);
        });
    });

    describe('buildBatchCheckKeys', function (): void {
        it('builds keyed array from checks', function (): void {
            $checks = [
                ['user' => 'user:1', 'relation' => 'read', 'object' => 'doc:1'],
                ['user' => 'user:2', 'relation' => 'write', 'object' => 'doc:2'],
            ];

            $keyed = $this->manager->buildBatchCheckKeys($checks);

            expect($keyed)->toHaveKey('user:1_read_doc:1');
            expect($keyed)->toHaveKey('user:2_write_doc:2');
            expect($keyed['user:1_read_doc:1'])->toBe($checks[0]);
        });

        it('handles duplicate checks', function (): void {
            $checks = [
                ['user' => 'user:1', 'relation' => 'read', 'object' => 'doc:1'],
                ['user' => 'user:1', 'relation' => 'read', 'object' => 'doc:1'],
            ];

            $keyed = $this->manager->buildBatchCheckKeys($checks);

            expect($keyed)->toHaveCount(1);
        });
    });

    describe('calculateMetrics', function (): void {
        it('calculates duration and memory', function (): void {
            $startTime = microtime(true) - 0.5; // 500ms ago

            $metrics = $this->manager->calculateMetrics($startTime);

            expect($metrics)->toHaveKey('duration');
            expect($metrics)->toHaveKey('memory');
            expect($metrics['duration'])->toBeGreaterThan(0.4);
            expect($metrics['duration'])->toBeLessThan(0.6);
            expect($metrics['memory'])->toBeGreaterThan(0);
        });
    });

    describe('sanitizeForLogging', function (): void {
        it('truncates long strings', function (): void {
            $longString = str_repeat('a', 150);

            $sanitized = $this->manager->sanitizeForLogging($longString);

            expect($sanitized)->toHaveLength(100);
            expect($sanitized)->toEndWith('...');
        });

        it('leaves short strings unchanged', function (): void {
            $shortString = 'short string';

            $sanitized = $this->manager->sanitizeForLogging($shortString);

            expect($sanitized)->toBe($shortString);
        });

        it('sanitizes arrays recursively', function (): void {
            $data = [
                'short' => 'value',
                'long' => str_repeat('b', 150),
                'nested' => [
                    'long' => str_repeat('c', 200),
                ],
            ];

            $sanitized = $this->manager->sanitizeForLogging($data);

            expect($sanitized['short'])->toBe('value');
            expect($sanitized['long'])->toHaveLength(100);
            expect($sanitized['nested']['long'])->toHaveLength(100);
        });

        it('handles non-string values', function (): void {
            expect($this->manager->sanitizeForLogging(123))->toBe(123);
            expect($this->manager->sanitizeForLogging(true))->toBe(true);
            expect($this->manager->sanitizeForLogging(null))->toBeNull();
        });
    });
});
