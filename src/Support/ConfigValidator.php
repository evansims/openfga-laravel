<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Support;

/**
 * Configuration validator for OpenFGA connections.
 *
 * This class provides validation logic for OpenFGA configuration,
 * extracted to allow for easier testing and reuse.
 */
class ConfigValidator
{
    /**
     * Validate a complete configuration array.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, array<string>>
     */
    public function validate(array $config): array
    {
        $errors = [];

        if (!isset($config['default'])) {
            $errors['default'][] = 'Default connection is required';
        }

        if (!isset($config['connections']) || !is_array($config['connections'])) {
            $errors['connections'][] = 'Connections array is required';
        } else {
            $errors = array_merge($errors, $this->validateConnections($config['connections']));
        }

        return $errors;
    }

    /**
     * Validate connections configuration.
     *
     * @param array<string, mixed> $connections
     *
     * @return array<string, array<string>>
     */
    public function validateConnections(array $connections): array
    {
        $errors = [];

        if (empty($connections)) {
            $errors['connections'][] = 'At least one connection must be configured';
        }

        foreach ($connections as $name => $connection) {
            if (!is_array($connection)) {
                $errors["connections.{$name}"][] = 'Connection configuration must be an array';
                continue;
            }

            $connectionErrors = $this->validateConnection($connection);
            foreach ($connectionErrors as $field => $fieldErrors) {
                $errors["connections.{$name}.{$field}"] = $fieldErrors;
            }
        }

        return $errors;
    }

    /**
     * Validate a single connection configuration.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, array<string>>
     */
    public function validateConnection(array $connection): array
    {
        $errors = [];

        // Validate URL
        if (!isset($connection['url']) || !is_string($connection['url'])) {
            $errors['url'][] = 'URL is required and must be a string';
        } elseif (!filter_var($connection['url'], FILTER_VALIDATE_URL)) {
            $errors['url'][] = 'URL must be a valid URL';
        }

        // Validate store ID
        if (!isset($connection['store_id']) || !is_string($connection['store_id'])) {
            $errors['store_id'][] = 'Store ID is required and must be a string';
        } elseif (empty(trim($connection['store_id']))) {
            $errors['store_id'][] = 'Store ID cannot be empty';
        }

        // Validate credentials if present
        if (isset($connection['credentials'])) {
            if (!is_array($connection['credentials'])) {
                $errors['credentials'][] = 'Credentials must be an array';
            } else {
                $credentialErrors = $this->validateCredentials($connection['credentials']);
                foreach ($credentialErrors as $field => $fieldErrors) {
                    $errors["credentials.{$field}"] = $fieldErrors;
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
     * Validate credential configuration.
     *
     * @param array<string, mixed> $credentials
     *
     * @return array<string, array<string>>
     */
    public function validateCredentials(array $credentials): array
    {
        $errors = [];
        $method = $credentials['method'] ?? null;

        if (!is_string($method)) {
            $errors['method'][] = 'Authentication method must be a string';
            return $errors;
        }

        switch ($method) {
            case 'none':
                // No validation needed
                break;

            case 'api_token':
                if (!isset($credentials['token']) || !is_string($credentials['token'])) {
                    $errors['token'][] = 'API token is required and must be a string';
                } elseif (empty($credentials['token'])) {
                    $errors['token'][] = 'API token cannot be empty';
                }
                break;

            case 'client_credentials':
                if (!isset($credentials['client_id']) || !is_string($credentials['client_id'])) {
                    $errors['client_id'][] = 'Client ID is required and must be a string';
                } elseif (empty($credentials['client_id'])) {
                    $errors['client_id'][] = 'Client ID cannot be empty';
                }

                if (!isset($credentials['client_secret']) || !is_string($credentials['client_secret'])) {
                    $errors['client_secret'][] = 'Client secret is required and must be a string';
                } elseif (empty($credentials['client_secret'])) {
                    $errors['client_secret'][] = 'Client secret cannot be empty';
                }

                // Need either token_endpoint or api_token_issuer
                $hasTokenEndpoint = isset($credentials['token_endpoint']) && !empty($credentials['token_endpoint']);
                $hasIssuer = isset($credentials['api_token_issuer']) && !empty($credentials['api_token_issuer']);

                if (!$hasTokenEndpoint && !$hasIssuer) {
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
     * @param array<string, mixed> $config
     * @param string $field
     * @param array<string, array<string>> $errors
     * @param int $min
     * @param int $max
     *
     * @return void
     */
    private function validateNumericField(
        array $config,
        string $field,
        array &$errors,
        int $min,
        int $max
    ): void {
        if (!isset($config[$field])) {
            return;
        }

        $value = $config[$field];

        if (!is_numeric($value)) {
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

    /**
     * Check if configuration is valid (no errors).
     *
     * @param array<string, mixed> $config
     *
     * @return bool
     */
    public function isValid(array $config): bool
    {
        return empty($this->validate($config));
    }

    /**
     * Get flat list of all error messages.
     *
     * @param array<string, array<string>> $errors
     *
     * @return array<string>
     */
    public function flattenErrors(array $errors): array
    {
        $flat = [];

        foreach ($errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $flat[] = sprintf('%s: %s', $field, $error);
            }
        }

        return $flat;
    }
}