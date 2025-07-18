<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default OpenFGA Connection
    |--------------------------------------------------------------------------
    |
    | This option controls the default OpenFGA connection that will be used
    | when interacting with OpenFGA. You may define as many connections as
    | needed for your application.
    |
    */

    'default' => env('OPENFGA_CONNECTION', 'main'),

    /*
    |--------------------------------------------------------------------------
    | OpenFGA Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many OpenFGA connections as needed for your
    | application. Each connection can have its own store, model, and
    | authentication configuration.
    |
    */

    'connections' => [
        'main' => [
            'url' => env('OPENFGA_URL', 'http://localhost:8080'),
            'store_id' => env('OPENFGA_STORE_ID'),
            'model_id' => env('OPENFGA_MODEL_ID'),

            /*
            |--------------------------------------------------------------------------
            | Authentication Configuration
            |--------------------------------------------------------------------------
            |
            | Configure how to authenticate with the OpenFGA API. Supported methods:
            | - "none": No authentication (for local development)
            | - "api_token": Pre-shared API token
            | - "client_credentials": OAuth 2.0 client credentials flow
            |
            */

            'credentials' => [
                'method' => env('OPENFGA_AUTH_METHOD', 'none'),

                // API Token Authentication
                'token' => env('OPENFGA_API_TOKEN'),

                // OAuth 2.0 Client Credentials
                'client_id' => env('OPENFGA_CLIENT_ID'),
                'client_secret' => env('OPENFGA_CLIENT_SECRET'),
                'api_token_issuer' => env('OPENFGA_TOKEN_ISSUER'),
                'api_audience' => env('OPENFGA_API_AUDIENCE'),
                'scopes' => env('OPENFGA_SCOPES') ? explode(',', env('OPENFGA_SCOPES')) : [],
            ],

            /*
            |--------------------------------------------------------------------------
            | Retry Configuration
            |--------------------------------------------------------------------------
            |
            | Configure automatic retry behavior with exponential backoff for
            | transient failures.
            |
            */

            'retries' => [
                'max_retries' => env('OPENFGA_MAX_RETRIES', 3),
                'min_wait_ms' => env('OPENFGA_MIN_WAIT_MS', 100),
            ],

            /*
            |--------------------------------------------------------------------------
            | HTTP Client Options
            |--------------------------------------------------------------------------
            |
            | Configure HTTP client behavior including timeouts.
            |
            */

            'http_options' => [
                'timeout' => env('OPENFGA_TIMEOUT', 30),
                'connect_timeout' => env('OPENFGA_CONNECT_TIMEOUT', 10),
            ],
        ],

        // Example: Additional connection for testing
        // 'testing' => [
        //     'url' => env('OPENFGA_TESTING_URL', 'http://localhost:8081'),
        //     'store_id' => env('OPENFGA_TESTING_STORE_ID'),
        //     'model_id' => env('OPENFGA_TESTING_MODEL_ID'),
        //     'credentials' => [
        //         'method' => 'none',
        //     ],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching behavior for permission checks. Caching can significantly
    | improve performance by reducing API calls.
    |
    */

    'cache' => [
        'enabled' => env('OPENFGA_CACHE_ENABLED', true),
        'store' => env('OPENFGA_CACHE_STORE'), // null = use default cache store
        'ttl' => env('OPENFGA_CACHE_TTL', 300), // 5 minutes
        'prefix' => 'openfga',

        // Read-through cache settings
        'read_through' => env('OPENFGA_CACHE_READ_THROUGH', true),
        'negative_ttl' => env('OPENFGA_CACHE_NEGATIVE_TTL', 60), // Cache negative results for 1 minute
        'error_ttl' => env('OPENFGA_CACHE_ERROR_TTL', 10), // Cache errors for 10 seconds
        'log_misses' => env('OPENFGA_CACHE_LOG_MISSES', false),

        // Cache metrics
        'metrics' => [
            'enabled' => env('OPENFGA_CACHE_METRICS_ENABLED', false),
        ],

        // Tagged cache support (for stores that support tagging)
        'tags' => [
            'enabled' => env('OPENFGA_CACHE_TAGS_ENABLED', true),
        ],

        // Write-behind cache for non-critical updates
        'write_behind' => [
            'enabled' => env('OPENFGA_WRITE_BEHIND_ENABLED', false),
            'store' => env('OPENFGA_WRITE_BEHIND_STORE'), // null = use default cache store
            'batch_size' => env('OPENFGA_WRITE_BEHIND_BATCH_SIZE', 100),
            'flush_interval' => env('OPENFGA_WRITE_BEHIND_FLUSH_INTERVAL', 5), // seconds
            'ttl' => env('OPENFGA_WRITE_BEHIND_TTL', 300), // 5 minutes
            'periodic_flush' => env('OPENFGA_WRITE_BEHIND_PERIODIC_FLUSH', false),
            'flush_on_shutdown' => env('OPENFGA_WRITE_BEHIND_FLUSH_ON_SHUTDOWN', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure queue behavior for batch operations. When enabled, batch writes
    | can be processed asynchronously for better performance.
    |
    */

    'queue' => [
        'enabled' => env('OPENFGA_QUEUE_ENABLED', false),
        'connection' => env('OPENFGA_QUEUE_CONNECTION'), // null = use default
        'queue' => env('OPENFGA_QUEUE_NAME', 'openfga'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure logging behavior for debugging and monitoring OpenFGA operations.
    |
    */

    'logging' => [
        'enabled' => env('OPENFGA_LOGGING_ENABLED', true),
        'channel' => env('OPENFGA_LOG_CHANNEL'), // null = use default channel
        'log_requests' => env('OPENFGA_LOG_REQUESTS', false),
        'log_responses' => env('OPENFGA_LOG_RESPONSES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Cleanup Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automatic cleanup behavior when Eloquent models are deleted.
    |
    */

    'cleanup_on_delete' => env('OPENFGA_CLEANUP_ON_DELETE', true),

    /*
    |--------------------------------------------------------------------------
    | Exception Handling
    |--------------------------------------------------------------------------
    |
    | Configure whether to throw exceptions or return false on authorization
    | failures. When false, the SDK's Result pattern will be used internally.
    |
    */

    'throw_exceptions' => env('OPENFGA_THROW_EXCEPTIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Connection Pool Configuration
    |--------------------------------------------------------------------------
    |
    | Configure connection pooling for better performance under high load.
    | This maintains a pool of reusable connections to OpenFGA.
    |
    */

    'pool' => [
        'enabled' => env('OPENFGA_POOL_ENABLED', false),
        'max_connections' => env('OPENFGA_POOL_MAX_CONNECTIONS', 10),
        'min_connections' => env('OPENFGA_POOL_MIN_CONNECTIONS', 2),
        'max_idle_time' => env('OPENFGA_POOL_MAX_IDLE_TIME', 300), // 5 minutes
        'connection_timeout' => env('OPENFGA_POOL_CONNECTION_TIMEOUT', 5), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configure webhooks to receive notifications when permissions change.
    | Each webhook can listen to specific events or all events.
    |
    */

    'webhooks' => [
        'enabled' => env('OPENFGA_WEBHOOKS_ENABLED', false),
        'timeout' => env('OPENFGA_WEBHOOK_TIMEOUT', 5),
        'retries' => env('OPENFGA_WEBHOOK_RETRIES', 3),
        'send_check_events' => env('OPENFGA_WEBHOOK_SEND_CHECKS', false),
        
        // Secret for verifying incoming webhooks from OpenFGA
        'secret' => env('OPENFGA_WEBHOOK_SECRET'),
        
        'endpoints' => [
            // 'primary' => [
            //     'url' => env('OPENFGA_WEBHOOK_URL'),
            //     'headers' => [
            //         'Authorization' => 'Bearer ' . env('OPENFGA_WEBHOOK_TOKEN'),
            //     ],
            //     'events' => ['permission.granted', 'permission.revoked'],
            //     'active' => true,
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Profiling Configuration
    |--------------------------------------------------------------------------
    |
    | Configure performance profiling to monitor and optimize OpenFGA operations.
    | Profiling can help identify slow queries and performance bottlenecks.
    |
    */

    'profiling' => [
        'enabled' => env('OPENFGA_PROFILING_ENABLED', false),
        'slow_query_threshold' => env('OPENFGA_SLOW_QUERY_THRESHOLD', 100), // milliseconds
        'log_slow_queries' => env('OPENFGA_LOG_SLOW_QUERIES', true),
        'inject_web_middleware' => env('OPENFGA_INJECT_WEB_MIDDLEWARE', false),
        
        // Laravel Debugbar integration
        'debugbar' => [
            'enabled' => env('OPENFGA_DEBUGBAR_ENABLED', true),
            'collector' => \OpenFga\Laravel\Debugbar\OpenFgaCollector::class,
        ],
        
        // Profiling storage
        'storage' => [
            'driver' => env('OPENFGA_PROFILING_STORAGE', 'memory'), // memory, database, file
            'table' => 'openfga_profiles',
            'path' => storage_path('openfga/profiles'),
            'ttl' => env('OPENFGA_PROFILING_TTL', 3600), // 1 hour
        ],
    ],
];