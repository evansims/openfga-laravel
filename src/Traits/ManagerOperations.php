<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Traits;

use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Models\Collections\TupleKeys;
use OpenFGA\Models\TupleKey;
use ReflectionException;

use function count;
use function is_array;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Trait containing testable operations for OpenFGA manager.
 *
 * This trait extracts pure functions and testable logic from the OpenFgaManager
 * to allow for easier unit testing without dealing with final class restrictions.
 */
trait ManagerOperations
{
    /**
     * Build batch check cache keys.
     *
     * @param  array<int, array{user: string, relation: string, object: string}>    $checks
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
                $check['object'],
            );
            $keyed[$key] = $check;
        }

        return $keyed;
    }

    /**
     * Build a cache key from operation parameters.
     *
     * @param string               $operation
     * @param array<string, mixed> $params
     */
    public function buildCacheKey(string $operation, array $params): string
    {
        // Sort params for consistent key generation
        ksort($params);

        return sprintf(
            'openfga:%s:%s',
            $operation,
            md5(serialize($params)),
        );
    }

    /**
     * Calculate operation metrics.
     *
     * @param  float                               $startTime
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
     * Extract object IDs from object strings.
     *
     * @param  array<string> $objects
     * @return array<string>
     */
    public function extractObjectIds(array $objects): array
    {
        $ids = [];

        foreach ($objects as $object) {
            // Extract ID from format "type:id"
            $parts = explode(':', $object, 2);

            if (2 === count($parts)) {
                $ids[] = $parts[1];
            }
        }

        return $ids;
    }

    /**
     * Normalize contextual tuples to TupleKey instances.
     *
     * @param array<array{user: string, relation: string, object: string}|TupleKey> $contextualTuples
     *
     * @throws ClientThrowable
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function normalizeContextualTuples(array $contextualTuples): TupleKeys
    {
        $tuples = new TupleKeys;

        foreach ($contextualTuples as $contextualTuple) {
            if ($contextualTuple instanceof TupleKey) {
                $tuples->add($contextualTuple);
            } else {
                $tuples->add(new TupleKey(
                    $contextualTuple['user'] ?? '',
                    $contextualTuple['relation'] ?? '',
                    $contextualTuple['object'] ?? '',
                ));
            }
        }

        return $tuples;
    }

    /**
     * Parse connection configuration for validation.
     *
     * @param  array<string, mixed>                                                                                     $config
     * @return array{url: string, store_id: string, authorization_model_id: ?string, credentials: array<string, mixed>}
     */
    public function parseConnectionConfig(array $config): array
    {
        /** @var mixed $url */
        $url = $config['url'] ?? '';

        /** @var mixed $storeId */
        $storeId = $config['store_id'] ?? '';

        /** @var mixed $modelId */
        $modelId = $config['authorization_model_id'] ?? null;

        /** @var mixed $credentials */
        $credentials = $config['credentials'] ?? [];

        /** @var array<string, mixed> $safeCredentials */
        $safeCredentials = is_array($credentials) ? $credentials : [];

        return [
            'url' => is_string($url) ? $url : '',
            'store_id' => is_string($storeId) ? $storeId : '',
            'authorization_model_id' => is_string($modelId) ? $modelId : null,
            'credentials' => $safeCredentials,
        ];
    }

    /**
     * Sanitize user input for logging.
     *
     * @param  mixed $value
     * @return mixed
     */
    public function sanitizeForLogging($value)
    {
        if (is_string($value) && 100 < strlen($value)) {
            return substr($value, 0, 97) . '...';
        }

        if (is_array($value)) {
            return array_map([$this, 'sanitizeForLogging'], $value);
        }

        return $value;
    }

    /**
     * Validate connection configuration.
     *
     * @param  array<string, mixed> $config
     * @return array<string>
     */
    public function validateConnectionConfig(array $config): array
    {
        $errors = [];
        $parsed = $this->parseConnectionConfig($config);

        if ('' === $parsed['url']) {
            $errors[] = 'URL is required';
        } elseif (false === filter_var($parsed['url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'URL must be a valid URL';
        }

        if ('' === $parsed['store_id']) {
            $errors[] = 'Store ID is required';
        }

        // Validate credentials
        $credentialErrors = $this->validateCredentials($parsed['credentials']);

        if ([] !== $credentialErrors) {
            return array_merge($errors, $credentialErrors);
        }

        return $errors;
    }

    /**
     * Validate credential configuration.
     *
     * @param  array<string, mixed> $credentials
     * @return array<string>
     */
    public function validateCredentials(array $credentials): array
    {
        $errors = [];

        /** @var mixed $methodValue */
        $methodValue = $credentials['method'] ?? 'none';
        $method = is_string($methodValue) ? $methodValue : 'none';

        switch ($method) {
            case 'api_token':
                if (! isset($credentials['token']) || '' === $credentials['token']) {
                    $errors[] = 'API token is required when using api_token authentication';
                }

                break;

            case 'client_credentials':
                if (! isset($credentials['client_id']) || '' === $credentials['client_id']) {
                    $errors[] = 'Client ID is required for client credentials';
                }

                if (! isset($credentials['client_secret']) || '' === $credentials['client_secret']) {
                    $errors[] = 'Client secret is required for client credentials';
                }

                $hasTokenEndpoint = isset($credentials['token_endpoint']) && '' !== $credentials['token_endpoint'];
                $hasIssuer = isset($credentials['api_token_issuer']) && '' !== $credentials['api_token_issuer'];

                if (! $hasTokenEndpoint && ! $hasIssuer) {
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
}
