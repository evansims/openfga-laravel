<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Traits;

use OpenFGA\Models\TupleKey;
use OpenFGA\Models\Collections\TupleKeys;

/**
 * Trait containing testable operations for OpenFGA manager.
 *
 * This trait extracts pure functions and testable logic from the OpenFgaManager
 * to allow for easier unit testing without dealing with final class restrictions.
 */
trait ManagerOperations
{
    /**
     * Normalize contextual tuples to TupleKey instances.
     *
     * @param array<array{user: string, relation: string, object: string}|TupleKey> $contextualTuples
     *
     * @return TupleKeys
     */
    public function normalizeContextualTuples(array $contextualTuples): TupleKeys
    {
        $tuples = new TupleKeys;

        foreach ($contextualTuples as $tuple) {
            if ($tuple instanceof TupleKey) {
                $tuples->add($tuple);
            } elseif (is_array($tuple)) {
                $tuples->add(new TupleKey(
                    $tuple['user'] ?? '',
                    $tuple['relation'] ?? '',
                    $tuple['object'] ?? '',
                ));
            }
        }

        return $tuples;
    }

    /**
     * Build a cache key from operation parameters.
     *
     * @param string $operation
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public function buildCacheKey(string $operation, array $params): string
    {
        // Sort params for consistent key generation
        ksort($params);
        
        return sprintf(
            'openfga:%s:%s',
            $operation,
            md5(serialize($params))
        );
    }

    /**
     * Parse connection configuration for validation.
     *
     * @param array<string, mixed> $config
     *
     * @return array{url: string, store_id: string, authorization_model_id: ?string, credentials: array<string, mixed>}
     */
    public function parseConnectionConfig(array $config): array
    {
        return [
            'url' => $config['url'] ?? '',
            'store_id' => $config['store_id'] ?? '',
            'authorization_model_id' => $config['authorization_model_id'] ?? null,
            'credentials' => $config['credentials'] ?? [],
        ];
    }

    /**
     * Validate connection configuration.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string>
     */
    public function validateConnectionConfig(array $config): array
    {
        $errors = [];
        $parsed = $this->parseConnectionConfig($config);

        if (empty($parsed['url'])) {
            $errors[] = 'URL is required';
        } elseif (!filter_var($parsed['url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'URL must be a valid URL';
        }

        if (empty($parsed['store_id'])) {
            $errors[] = 'Store ID is required';
        }

        // Validate credentials
        $credentialErrors = $this->validateCredentials($parsed['credentials']);
        if (!empty($credentialErrors)) {
            $errors = array_merge($errors, $credentialErrors);
        }

        return $errors;
    }

    /**
     * Validate credential configuration.
     *
     * @param array<string, mixed> $credentials
     *
     * @return array<string>
     */
    public function validateCredentials(array $credentials): array
    {
        $errors = [];
        $method = $credentials['method'] ?? 'none';

        switch ($method) {
            case 'api_token':
                if (empty($credentials['token'])) {
                    $errors[] = 'API token is required when using api_token authentication';
                }
                break;

            case 'client_credentials':
                if (empty($credentials['client_id'])) {
                    $errors[] = 'Client ID is required for client credentials';
                }
                if (empty($credentials['client_secret'])) {
                    $errors[] = 'Client secret is required for client credentials';
                }
                if (empty($credentials['token_endpoint']) && empty($credentials['api_token_issuer'])) {
                    $errors[] = 'Either token_endpoint or api_token_issuer is required for client credentials';
                }
                break;

            case 'none':
                // No validation needed
                break;

            default:
                $errors[] = sprintf('Unknown authentication method: %s', $method);
        }

        return $errors;
    }

    /**
     * Extract object IDs from object strings.
     *
     * @param array<string> $objects
     *
     * @return array<string>
     */
    public function extractObjectIds(array $objects): array
    {
        $ids = [];

        foreach ($objects as $object) {
            // Extract ID from format "type:id"
            $parts = explode(':', $object, 2);
            if (count($parts) === 2) {
                $ids[] = $parts[1];
            }
        }

        return $ids;
    }

    /**
     * Build batch check cache keys.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $checks
     *
     * @return array<string, array{user: string, relation: string, object: string}>
     */
    public function buildBatchCheckKeys(array $checks): array
    {
        $keyed = [];

        foreach ($checks as $check) {
            $key = sprintf(
                '%s_%s_%s',
                $check['user'],
                $check['relation'],
                $check['object']
            );
            $keyed[$key] = $check;
        }

        return $keyed;
    }

    /**
     * Calculate operation metrics.
     *
     * @param float $startTime
     *
     * @return array{duration: float, memory: int}
     */
    public function calculateMetrics(float $startTime): array
    {
        return [
            'duration' => microtime(true) - $startTime,
            'memory' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Sanitize user input for logging.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function sanitizeForLogging($value)
    {
        if (is_string($value) && strlen($value) > 100) {
            return substr($value, 0, 97) . '...';
        }

        if (is_array($value)) {
            return array_map([$this, 'sanitizeForLogging'], $value);
        }

        return $value;
    }
}