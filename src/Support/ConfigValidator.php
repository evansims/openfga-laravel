<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Support;

use function array_key_exists;
use function is_array;
use function is_numeric;
use function is_string;
use function sprintf;
use function str_replace;
use function ucfirst;

/**
 * Configuration validator for OpenFGA connections.
 *
 * This class provides validation logic for OpenFGA configuration,
 * extracted to allow for easier testing and reuse.
 */
final class ConfigValidator
{
    /**
     * Get flat list of all error messages.
     *
     * @param  array<string, array<string>> $errors
     * @return array<string>
     */
    public function flattenErrors(array $errors): array
    {
        $flat = [];

        foreach ($errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $fieldError) {
                $flat[] = sprintf('%s: %s', $field, $fieldError);
            }
        }

        return $flat;
    }

    /**
     * Check if configuration is valid (no errors).
     *
     * @param array<string, mixed> $config
     */
    public function isValid(array $config): bool
    {
        return [] === $this->validate($config);
    }

    /**
     * Validate a complete configuration array.
     *
     * @param  array<string, mixed>         $config
     * @return array<string, array<string>>
     */
    public function validate(array $config): array
    {
        $errors = [];

        if (! array_key_exists('default', $config)) {
            $errors['default'][] = 'Default connection is required';
        }

        if (! array_key_exists('connections', $config) || ! is_array($config['connections'])) {
            $errors['connections'][] = 'Connections array is required';
        } else {
            /** @var array<string, mixed> $connections */
            $connections = $config['connections'];
            $errors = array_merge($errors, $this->validateConnections($connections));
        }

        return $errors;
    }

    /**
     * Validate a single connection configuration.
     *
     * @param  array<string, mixed>         $connection
     * @return array<string, array<string>>
     */
    public function validateConnection(array $connection): array
    {
        $errors = [];

        // Validate URL
        if (! array_key_exists('url', $connection) || ! is_string($connection['url'])) {
            $errors['url'][] = 'URL is required and must be a string';
        } else {
            $url = $connection['url'];

            if (false === filter_var($url, FILTER_VALIDATE_URL)) {
                $errors['url'][] = 'URL must be a valid URL';
            }
        }

        // Validate store ID
        if (! array_key_exists('store_id', $connection) || ! is_string($connection['store_id'])) {
            $errors['store_id'][] = 'Store ID is required and must be a string';
        } else {
            $storeId = $connection['store_id'];

            if ('' === trim($storeId)) {
                $errors['store_id'][] = 'Store ID cannot be empty';
            }
        }

        // Validate credentials if present
        if (array_key_exists('credentials', $connection)) {
            /** @var mixed $credentialsValue */
            $credentialsValue = $connection['credentials'];

            if (! is_array($credentialsValue)) {
                $errors['credentials'][] = 'Credentials must be an array';
            } else {
                /** @var array<string, mixed> $credentials */
                $credentials = $credentialsValue;
                $credentialErrors = $this->validateCredentials($credentials);

                foreach ($credentialErrors as $field => $fieldErrors) {
                    $errors['credentials.' . $field] = $fieldErrors;
                }
            }
        }

        // Validate optional numeric fields
        $this->validateNumericField($connection, 'max_retries', $errors, 0, 100);
        $this->validateNumericField($connection, 'connect_timeout', $errors, 1, 300);
        $this->validateNumericField($connection, 'timeout', $errors, 1, 300);

        return $errors;
    }

    /**
     * Validate connections configuration.
     *
     * @param  array<string, mixed>         $connections
     * @return array<string, array<string>>
     */
    public function validateConnections(array $connections): array
    {
        $errors = [];

        if ([] === $connections) {
            $errors['connections'][] = 'At least one connection must be configured';
        }

        foreach ($connections as $name => $connection) {
            if (! is_array($connection)) {
                $errors['connections.' . $name][] = 'Connection configuration must be an array';

                continue;
            }

            /** @var array<string, mixed> $connection */
            $connectionErrors = $this->validateConnection($connection);

            foreach ($connectionErrors as $field => $fieldErrors) {
                $errors[sprintf('connections.%s.%s', $name, $field)] = $fieldErrors;
            }
        }

        return $errors;
    }

    /**
     * Validate credential configuration.
     *
     * @param  array<string, mixed>         $credentials
     * @return array<string, array<string>>
     */
    public function validateCredentials(array $credentials): array
    {
        $errors = [];
        $method = $credentials['method'] ?? null;

        if (! is_string($method)) {
            $errors['method'][] = 'Authentication method must be a string';

            return $errors;
        }

        switch ($method) {
            case 'none':
                // No validation needed
                break;

            case 'api_token':
                if (! array_key_exists('token', $credentials) || ! is_string($credentials['token'])) {
                    $errors['token'][] = 'API token is required and must be a string';
                } else {
                    $token = $credentials['token'];

                    if ('' === $token) {
                        $errors['token'][] = 'API token cannot be empty';
                    }
                }

                break;

            case 'client_credentials':
                if (! array_key_exists('client_id', $credentials) || ! is_string($credentials['client_id'])) {
                    $errors['client_id'][] = 'Client ID is required and must be a string';
                } else {
                    $clientId = $credentials['client_id'];

                    if ('' === $clientId) {
                        $errors['client_id'][] = 'Client ID cannot be empty';
                    }
                }

                if (! array_key_exists('client_secret', $credentials) || ! is_string($credentials['client_secret'])) {
                    $errors['client_secret'][] = 'Client secret is required and must be a string';
                } else {
                    $clientSecret = $credentials['client_secret'];

                    if ('' === $clientSecret) {
                        $errors['client_secret'][] = 'Client secret cannot be empty';
                    }
                }

                // Need either token_endpoint or api_token_issuer
                $hasTokenEndpoint = array_key_exists('token_endpoint', $credentials) && is_string($credentials['token_endpoint']) && '' !== $credentials['token_endpoint'];
                $hasIssuer = array_key_exists('api_token_issuer', $credentials) && is_string($credentials['api_token_issuer']) && '' !== $credentials['api_token_issuer'];

                if (! $hasTokenEndpoint && ! $hasIssuer) {
                    $errors['token_endpoint'][] = 'Either token_endpoint or api_token_issuer is required';
                }

                break;

            default:
                $errors['method'][] = sprintf('Unknown authentication method: %s', $method);
        }

        return $errors;
    }

    /**
     * Validate a numeric field.
     *
     * @param array<string, mixed>         $config
     * @param string                       $field
     * @param array<string, array<string>> $errors
     * @param int                          $min
     * @param int                          $max
     */
    private function validateNumericField(
        array $config,
        string $field,
        array &$errors,
        int $min,
        int $max,
    ): void {
        if (! array_key_exists($field, $config)) {
            return;
        }

        $value = $config[$field];

        if (! is_numeric($value)) {
            $errors[$field][] = sprintf('%s must be numeric', ucfirst(str_replace('_', ' ', $field)));

            return;
        }

        $intValue = (int) $value;

        if ($intValue < $min) {
            $errors[$field][] = sprintf('%s must be at least %d', ucfirst(str_replace('_', ' ', $field)), $min);
        }

        if ($intValue > $max) {
            $errors[$field][] = sprintf('%s must be at most %d', ucfirst(str_replace('_', ' ', $field)), $max);
        }
    }
}
