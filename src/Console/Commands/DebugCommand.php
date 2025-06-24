<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use OpenFGA\Laravel\OpenFgaManager;

use function is_array;
use function is_scalar;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Command to debug OpenFGA configuration and connection.
 */
final class DebugCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Debug OpenFGA configuration and test connections';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:debug
                            {--connection= : The connection to debug}
                            {--test : Test the connection}';

    /**
     * Execute the console command.
     *
     * @param OpenFgaManager $manager
     */
    public function handle(OpenFgaManager $manager): int
    {
        $connection = $this->option('connection');
        $connection = is_string($connection) ? $connection : null;

        $this->info('OpenFGA Laravel Debug Information');
        $this->line('=================================');
        $this->newLine();

        // Display package version
        $this->displayPackageInfo();
        $this->newLine();

        // Display configuration
        $this->displayConfiguration($connection);
        $this->newLine();

        // Test connection if requested
        if (true === $this->option('test')) {
            $this->testConnection($manager, $connection);
            $this->newLine();
        }

        // Display cache information
        $this->displayCacheInfo();
        $this->newLine();

        // Display queue information
        $this->displayQueueInfo();

        return Command::SUCCESS;
    }

    /**
     * Display cache information.
     */
    private function displayCacheInfo(): void
    {
        $this->info('Cache Configuration:');

        $cacheConfig = Config::get('openfga.cache', []);

        $enabled = is_array($cacheConfig) && isset($cacheConfig['enabled']) && (bool) $cacheConfig['enabled'];
        $store = 'default';

        if (is_array($cacheConfig) && isset($cacheConfig['store']) && is_string($cacheConfig['store'])) {
            $store = $cacheConfig['store'];
        }
        $ttl = 300;

        if (is_array($cacheConfig) && isset($cacheConfig['ttl']) && is_numeric($cacheConfig['ttl'])) {
            $ttl = (int) $cacheConfig['ttl'];
        }
        $prefix = 'openfga';

        if (is_array($cacheConfig) && isset($cacheConfig['prefix']) && is_string($cacheConfig['prefix'])) {
            $prefix = $cacheConfig['prefix'];
        }

        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', $enabled ? 'Yes' : 'No'],
                ['Store', $store],
                ['TTL (seconds)', $ttl],
                ['Prefix', $prefix],
            ],
        );

        if ($enabled && $this->output->isVerbose()) {
            $defaultStoreConfig = Config::get('cache.default', 'file');
            $defaultStore = is_string($defaultStoreConfig) ? $defaultStoreConfig : 'file';
            $cacheStore = Config::get('cache.stores.' . $store, Config::get('cache.stores.' . $defaultStore, []));

            if (is_array($cacheStore)) {
                $this->newLine();
                $this->line('Cache Store Details:');

                $driver = isset($cacheStore['driver']) && is_scalar($cacheStore['driver']) ? (string) $cacheStore['driver'] : 'unknown';
                $this->line('  Driver: ' . $driver);

                if (isset($cacheStore['connection']) && is_scalar($cacheStore['connection'])) {
                    $this->line('  Connection: ' . (string) $cacheStore['connection']);
                }
            }
        }
    }

    /**
     * Display configuration details.
     *
     * @param ?string $connection
     */
    private function displayConfiguration(?string $connection): void
    {
        $this->info('Configuration:');

        $config = Config::get('openfga', []);
        $defaultConnection = is_array($config) && isset($config['default']) && is_scalar($config['default']) ? (string) $config['default'] : 'main';
        $connectionName = $connection ?? $defaultConnection;

        if (! is_array($config) || ! isset($config['connections']) || ! is_array($config['connections']) || ! isset($config['connections'][$connectionName])) {
            $this->error(sprintf("Connection '%s' not found in configuration", $connectionName));

            return;
        }

        $connectionConfig = $config['connections'][$connectionName];

        if (! is_array($connectionConfig)) {
            $this->error(sprintf("Connection '%s' configuration is invalid", $connectionName));

            return;
        }

        // Basic configuration
        $this->line('Connection: ' . $connectionName . ($connectionName === $defaultConnection ? ' (default)' : ''));
        $url = isset($connectionConfig['url']) && is_scalar($connectionConfig['url']) ? (string) $connectionConfig['url'] : 'Not set';
        $storeId = isset($connectionConfig['store_id']) && is_scalar($connectionConfig['store_id']) ? (string) $connectionConfig['store_id'] : 'Not set';
        $modelId = isset($connectionConfig['model_id']) && is_scalar($connectionConfig['model_id']) ? (string) $connectionConfig['model_id'] : 'Not set';
        $authMethod = 'none';

        if (isset($connectionConfig['credentials']) && is_array($connectionConfig['credentials']) && isset($connectionConfig['credentials']['method']) && is_scalar($connectionConfig['credentials']['method'])) {
            $authMethod = (string) $connectionConfig['credentials']['method'];
        }

        $this->table(
            ['Setting', 'Value'],
            [
                ['URL', $url],
                ['Store ID', $this->maskValue($storeId)],
                ['Model ID', $this->maskValue($modelId)],
                ['Auth Method', $authMethod],
            ],
        );

        // Advanced settings
        if ($this->output->isVerbose()) {
            $this->newLine();
            $this->info('Advanced Settings:');
            $maxRetries = 3;

            if (isset($connectionConfig['retries']) && is_array($connectionConfig['retries']) && isset($connectionConfig['retries']['max_retries']) && is_numeric($connectionConfig['retries']['max_retries'])) {
                $maxRetries = (int) $connectionConfig['retries']['max_retries'];
            }

            $minWait = 100;

            if (isset($connectionConfig['retries']) && is_array($connectionConfig['retries']) && isset($connectionConfig['retries']['min_wait_ms']) && is_numeric($connectionConfig['retries']['min_wait_ms'])) {
                $minWait = (int) $connectionConfig['retries']['min_wait_ms'];
            }

            $timeout = 30;

            if (isset($connectionConfig['http_options']) && is_array($connectionConfig['http_options']) && isset($connectionConfig['http_options']['timeout']) && is_numeric($connectionConfig['http_options']['timeout'])) {
                $timeout = (int) $connectionConfig['http_options']['timeout'];
            }

            $connectTimeout = 10;

            if (isset($connectionConfig['http_options']) && is_array($connectionConfig['http_options']) && isset($connectionConfig['http_options']['connect_timeout']) && is_numeric($connectionConfig['http_options']['connect_timeout'])) {
                $connectTimeout = (int) $connectionConfig['http_options']['connect_timeout'];
            }

            $this->table(
                ['Setting', 'Value'],
                [
                    ['Max Retries', $maxRetries],
                    ['Min Wait (ms)', $minWait],
                    ['Timeout (s)', $timeout],
                    ['Connect Timeout (s)', $connectTimeout],
                ],
            );
        }
    }

    /**
     * Display default package information.
     */
    private function displayDefaultPackageInfo(): void
    {
        $this->table(
            ['Property', 'Value'],
            [
                ['Package', 'openfga/laravel'],
                ['Laravel Version', app()->version()],
                ['PHP Version', PHP_VERSION],
            ],
        );
    }

    /**
     * Display package information.
     */
    private function displayPackageInfo(): void
    {
        $this->info('Package Information:');

        $composerFile = base_path('vendor/openfga/laravel/composer.json');

        if (file_exists($composerFile)) {
            $contents = file_get_contents($composerFile);

            if (false !== $contents) {
                $composer = json_decode($contents, true);
                $packageName = is_array($composer) && isset($composer['name']) && is_scalar($composer['name']) ? (string) $composer['name'] : 'openfga/laravel';
                $packageVersion = is_array($composer) && isset($composer['version']) && is_scalar($composer['version']) ? (string) $composer['version'] : 'dev';

                $this->table(
                    ['Property', 'Value'],
                    [
                        ['Package', $packageName],
                        ['Version', $packageVersion],
                        ['Laravel Version', app()->version()],
                        ['PHP Version', PHP_VERSION],
                    ],
                );
            } else {
                $this->displayDefaultPackageInfo();
            }
        } else {
            $this->displayDefaultPackageInfo();
        }
    }

    /**
     * Display queue information.
     */
    private function displayQueueInfo(): void
    {
        $this->info('Queue Configuration:');

        $queueConfig = Config::get('openfga.queue', []);

        $enabled = is_array($queueConfig) && isset($queueConfig['enabled']) && (bool) $queueConfig['enabled'];
        $connection = is_array($queueConfig) && isset($queueConfig['connection']) && is_scalar($queueConfig['connection']) ? (string) $queueConfig['connection'] : 'default';
        $queueName = is_array($queueConfig) && isset($queueConfig['queue']) && is_scalar($queueConfig['queue']) ? (string) $queueConfig['queue'] : 'openfga';

        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', $enabled ? 'Yes' : 'No'],
                ['Connection', $connection],
                ['Queue Name', $queueName],
            ],
        );

        if ($enabled && $this->output->isVerbose()) {
            $defaultQueue = Config::get('queue.default', 'sync');
            $connectionKey = 'queue.connections.' . $connection;
            $defaultKey = is_string($defaultQueue) ? 'queue.connections.' . $defaultQueue : null;
            $queueConnection = Config::get($connectionKey, null !== $defaultKey ? Config::get($defaultKey, []) : []);

            if (is_array($queueConnection)) {
                $this->newLine();
                $this->line('Queue Connection Details:');

                $driver = isset($queueConnection['driver']) && is_scalar($queueConnection['driver']) ? (string) $queueConnection['driver'] : 'unknown';
                $this->line('  Driver: ' . $driver);

                if (isset($queueConnection['queue'])) {
                    $queue = $queueConnection['queue'];
                    $queueStr = is_scalar($queue) ? (string) $queue : 'non-scalar value';
                    $this->line('  Default Queue: ' . $queueStr);
                }
            }
        }
    }

    /**
     * Mask sensitive values for display.
     *
     * @param string $value
     */
    private function maskValue(string $value): string
    {
        if ('Not set' === $value || 8 > strlen($value)) {
            return $value;
        }

        $visibleLength = min(4, (int) (strlen($value) / 4));

        return substr($value, 0, $visibleLength) . str_repeat('*', strlen($value) - $visibleLength * 2) . substr($value, -$visibleLength);
    }

    /**
     * Test the connection to OpenFGA.
     *
     * @param OpenFgaManager $manager
     * @param ?string        $connection
     */
    private function testConnection(OpenFgaManager $manager, ?string $connection): void
    {
        $this->info('Testing Connection:');
        $this->line('Attempting to connect to OpenFGA...');

        try {
            $startTime = microtime(true);

            // Try to perform a simple check operation
            $manager->connection($connection)->check('test:user', 'test', 'test:object');

            $duration = round((float) ((microtime(true) - $startTime) * 1000), 2);

            $this->info(sprintf('✅ Connection successful! (Response time: %sms)', $duration));
        } catch (Exception $exception) {
            $this->error('❌ Connection failed!');
            $this->error('Error: ' . $exception->getMessage());

            if ($this->output->isVerbose()) {
                $this->newLine();
                $this->error('Stack trace:');
                $this->line($exception->getTraceAsString());
            }
        }
    }
}
