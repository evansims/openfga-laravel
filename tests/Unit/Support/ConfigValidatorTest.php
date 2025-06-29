<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Support;

use OpenFGA\Laravel\Support\ConfigValidator;

describe('ConfigValidator', function (): void {
    beforeEach(function (): void {
        $this->validator = new ConfigValidator();
    });

    describe('validate', function (): void {
        it('validates complete valid configuration', function (): void {
            $config = [
                'default' => 'main',
                'connections' => [
                    'main' => [
                        'url' => 'https://api.example.com',
                        'store_id' => 'store-123',
                        'credentials' => ['method' => 'none'],
                    ],
                ],
            ];

            $errors = $this->validator->validate($config);

            expect($errors)->toBeEmpty();
        });

        it('requires default connection', function (): void {
            $config = [
                'connections' => [
                    'main' => ['url' => 'https://api.example.com', 'store_id' => 'store-123'],
                ],
            ];

            $errors = $this->validator->validate($config);

            expect($errors)->toHaveKey('default');
            expect($errors['default'])->toContain('Default connection is required');
        });

        it('requires connections array', function (): void {
            $config = ['default' => 'main'];

            $errors = $this->validator->validate($config);

            expect($errors)->toHaveKey('connections');
            expect($errors['connections'])->toContain('Connections array is required');
        });

        it('validates connections is array', function (): void {
            $config = [
                'default' => 'main',
                'connections' => 'not-an-array',
            ];

            $errors = $this->validator->validate($config);

            expect($errors)->toHaveKey('connections');
        });
    });

    describe('validateConnections', function (): void {
        it('requires at least one connection', function (): void {
            $errors = $this->validator->validateConnections([]);

            expect($errors)->toHaveKey('connections');
            expect($errors['connections'])->toContain('At least one connection must be configured');
        });

        it('validates each connection is array', function (): void {
            $connections = [
                'main' => 'not-an-array',
                'secondary' => ['url' => 'https://api.example.com', 'store_id' => 'store-123'],
            ];

            $errors = $this->validator->validateConnections($connections);

            expect($errors)->toHaveKey('connections.main');
            expect($errors['connections.main'])->toContain('Connection configuration must be an array');
            expect($errors)->not->toHaveKey('connections.secondary.url');
        });
    });

    describe('validateConnection', function (): void {
        it('validates URL is required', function (): void {
            $connection = ['store_id' => 'store-123'];

            $errors = $this->validator->validateConnection($connection);

            expect($errors)->toHaveKey('url');
            expect($errors['url'])->toContain('URL is required and must be a string');
        });

        it('validates URL format', function (): void {
            $connection = [
                'url' => 'not-a-valid-url',
                'store_id' => 'store-123',
            ];

            $errors = $this->validator->validateConnection($connection);

            expect($errors)->toHaveKey('url');
            expect($errors['url'])->toContain('URL must be a valid URL');
        });

        it('validates store ID is required', function (): void {
            $connection = ['url' => 'https://api.example.com'];

            $errors = $this->validator->validateConnection($connection);

            expect($errors)->toHaveKey('store_id');
            expect($errors['store_id'])->toContain('Store ID is required and must be a string');
        });

        it('validates store ID is not empty', function (): void {
            $connection = [
                'url' => 'https://api.example.com',
                'store_id' => '   ',
            ];

            $errors = $this->validator->validateConnection($connection);

            expect($errors)->toHaveKey('store_id');
            expect($errors['store_id'])->toContain('Store ID cannot be empty');
        });

        it('validates numeric fields', function (): void {
            $connection = [
                'url' => 'https://api.example.com',
                'store_id' => 'store-123',
                'max_retries' => 'not-a-number',
                'connect_timeout' => -5,
                'timeout' => 400,
            ];

            $errors = $this->validator->validateConnection($connection);

            expect($errors)->toHaveKey('max_retries');
            expect($errors['max_retries'])->toContain('Max retries must be numeric');

            expect($errors)->toHaveKey('connect_timeout');
            expect($errors['connect_timeout'])->toContain('Connect timeout must be at least 1');

            expect($errors)->toHaveKey('timeout');
            expect($errors['timeout'])->toContain('Timeout must be at most 300');
        });

        it('accepts valid numeric fields', function (): void {
            $connection = [
                'url' => 'https://api.example.com',
                'store_id' => 'store-123',
                'max_retries' => 3,
                'connect_timeout' => 10,
                'timeout' => 30,
            ];

            $errors = $this->validator->validateConnection($connection);

            expect($errors)->toBeEmpty();
        });
    });

    describe('validateCredentials', function (): void {
        it('validates method is required', function (): void {
            $credentials = [];

            $errors = $this->validator->validateCredentials($credentials);

            expect($errors)->toHaveKey('method');
            expect($errors['method'])->toContain('Authentication method must be a string');
        });

        it('accepts none method', function (): void {
            $credentials = ['method' => 'none'];

            $errors = $this->validator->validateCredentials($credentials);

            expect($errors)->toBeEmpty();
        });

        it('validates api_token requirements', function (): void {
            $credentials = ['method' => 'api_token'];

            $errors = $this->validator->validateCredentials($credentials);

            expect($errors)->toHaveKey('token');
            expect($errors['token'])->toContain('API token is required and must be a string');
        });

        it('validates api_token not empty', function (): void {
            $credentials = [
                'method' => 'api_token',
                'token' => '',
            ];

            $errors = $this->validator->validateCredentials($credentials);

            expect($errors)->toHaveKey('token');
            expect($errors['token'])->toContain('API token cannot be empty');
        });

        it('accepts valid api_token', function (): void {
            $credentials = [
                'method' => 'api_token',
                'token' => 'secret-token',
            ];

            $errors = $this->validator->validateCredentials($credentials);

            expect($errors)->toBeEmpty();
        });

        it('validates client_credentials requirements', function (): void {
            $credentials = ['method' => 'client_credentials'];

            $errors = $this->validator->validateCredentials($credentials);

            expect($errors)->toHaveKey('client_id');
            expect($errors)->toHaveKey('client_secret');
            expect($errors)->toHaveKey('token_endpoint');
        });

        it('accepts client_credentials with token_endpoint', function (): void {
            $credentials = [
                'method' => 'client_credentials',
                'client_id' => 'client-123',
                'client_secret' => 'secret',
                'token_endpoint' => 'https://auth.example.com/token',
            ];

            $errors = $this->validator->validateCredentials($credentials);

            expect($errors)->toBeEmpty();
        });

        it('accepts client_credentials with api_token_issuer', function (): void {
            $credentials = [
                'method' => 'client_credentials',
                'client_id' => 'client-123',
                'client_secret' => 'secret',
                'api_token_issuer' => 'https://auth.example.com',
            ];

            $errors = $this->validator->validateCredentials($credentials);

            expect($errors)->toBeEmpty();
        });

        it('rejects unknown authentication method', function (): void {
            $credentials = ['method' => 'unknown'];

            $errors = $this->validator->validateCredentials($credentials);

            expect($errors)->toHaveKey('method');
            expect($errors['method'])->toContain('Unknown authentication method: unknown');
        });
    });

    describe('isValid', function (): void {
        it('returns true for valid config', function (): void {
            $config = [
                'default' => 'main',
                'connections' => [
                    'main' => [
                        'url' => 'https://api.example.com',
                        'store_id' => 'store-123',
                    ],
                ],
            ];

            expect($this->validator->isValid($config))->toBeTrue();
        });

        it('returns false for invalid config', function (): void {
            $config = ['connections' => []];

            expect($this->validator->isValid($config))->toBeFalse();
        });
    });

    describe('flattenErrors', function (): void {
        it('flattens nested error array', function (): void {
            $errors = [
                'default' => ['Default connection is required'],
                'connections.main.url' => ['URL is required', 'URL must be valid'],
                'connections.main.store_id' => ['Store ID is required'],
            ];

            $flat = $this->validator->flattenErrors($errors);

            expect($flat)->toBe([
                'default: Default connection is required',
                'connections.main.url: URL is required',
                'connections.main.url: URL must be valid',
                'connections.main.store_id: Store ID is required',
            ]);
        });

        it('handles empty errors', function (): void {
            $flat = $this->validator->flattenErrors([]);

            expect($flat)->toBeEmpty();
        });
    });
});