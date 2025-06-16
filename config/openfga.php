<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | OpenFGA API URL
    |--------------------------------------------------------------------------
    |
    | The URL of your OpenFGA API server. For local development, this is
    | typically http://localhost:8080. In production, point this to your
    | hosted OpenFGA instance.
    |
    */
    'api_url' => env('OPENFGA_API_URL', 'http://localhost:8080'),

    /*
    |--------------------------------------------------------------------------
    | Store ID
    |--------------------------------------------------------------------------
    |
    | The OpenFGA store ID to use for all operations. You can override this
    | on a per-request basis by passing a different store ID to the client
    | methods. Leave null to require explicit store ID on each operation.
    |
    */
    'store_id' => env('OPENFGA_STORE_ID'),

    /*
    |--------------------------------------------------------------------------
    | Authorization Model ID
    |--------------------------------------------------------------------------
    |
    | The default authorization model ID to use. If not specified, OpenFGA
    | will use the latest model. You can override this per-request.
    |
    */
    'authorization_model_id' => env('OPENFGA_MODEL_ID'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Credentials
    |--------------------------------------------------------------------------
    |
    | Configure how to authenticate with your OpenFGA server. Set to null
    | for no authentication (typical for local development).
    |
    | For API token authentication:
    | 'credentials' => env('OPENFGA_API_TOKEN'),
    |
    | For client credentials (OAuth 2.0):
    | 'credentials' => [
    |     'method' => 'client_credentials',
    |     'client_id' => env('OPENFGA_CLIENT_ID'),
    |     'client_secret' => env('OPENFGA_CLIENT_SECRET'),
    |     'api_token_issuer' => env('OPENFGA_TOKEN_ISSUER'),
    |     'api_audience' => env('OPENFGA_API_AUDIENCE'),
    |     'scopes' => ['read', 'write'],
    | ],
    |
    */
    'credentials' => env('OPENFGA_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automatic retry behavior for failed requests. This helps
    | handle transient network issues and temporary server problems.
    |
    */
    'retry' => [
        'enabled' => env('OPENFGA_RETRY_ENABLED', true),
        'max_retries' => env('OPENFGA_MAX_RETRIES', 3),
        'retry_delay' => env('OPENFGA_RETRY_DELAY', 1000), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration
    |--------------------------------------------------------------------------
    |
    | Additional configuration for the underlying HTTP client. These values
    | are passed directly to the HTTP client constructor.
    |
    */
    'http' => [
        'timeout' => env('OPENFGA_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('OPENFGA_HTTP_CONNECT_TIMEOUT', 10),
    ],
];