<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Command to debug OpenFGA configuration and connection.
 */
class DebugCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:debug
                            {--connection= : The connection to debug}
                            {--test : Test the connection}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug OpenFGA configuration and test connections';

    /**
     * Execute the console command.
     */
    public function handle(OpenFgaManager $manager): int
    {
        $connection = $this->option('connection');

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
        if ($this->option('test')) {
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
     * Display package information.
     */
    protected function displayPackageInfo(): void
    {
        $this->info('Package Information:');
        
        $composerFile = base_path('vendor/openfga/laravel/composer.json');
        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true);
            $this->table(
                ['Property', 'Value'],
                [
                    ['Package', $composer['name'] ?? 'openfga/laravel'],
                    ['Version', $composer['version'] ?? 'dev'],
                    ['Laravel Version', app()->version()],
                    ['PHP Version', PHP_VERSION],
                ]
            );
        } else {
            $this->table(
                ['Property', 'Value'],
                [
                    ['Package', 'openfga/laravel'],
                    ['Laravel Version', app()->version()],
                    ['PHP Version', PHP_VERSION],
                ]
            );
        }
    }

    /**
     * Display configuration details.
     */
    protected function displayConfiguration(?string $connection): void
    {
        $this->info('Configuration:');

        $config = Config::get('openfga');
        $defaultConnection = $config['default'] ?? 'main';
        $connectionName = $connection ?? $defaultConnection;

        if (!isset($config['connections'][$connectionName])) {
            $this->error("Connection '{$connectionName}' not found in configuration");
            return;
        }

        $connectionConfig = $config['connections'][$connectionName];

        // Basic configuration
        $this->line('Connection: ' . $connectionName . ($connectionName === $defaultConnection ? ' (default)' : ''));
        $this->table(
            ['Setting', 'Value'],
            [
                ['URL', $connectionConfig['url'] ?? 'Not set'],
                ['Store ID', $this->maskValue($connectionConfig['store_id'] ?? 'Not set')],
                ['Model ID', $this->maskValue($connectionConfig['model_id'] ?? 'Not set')],
                ['Auth Method', $connectionConfig['credentials']['method'] ?? 'none'],
            ]
        );

        // Advanced settings
        if ($this->output->isVerbose()) {
            $this->newLine();
            $this->info('Advanced Settings:');
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Max Retries', $connectionConfig['retries']['max_retries'] ?? 3],
                    ['Min Wait (ms)', $connectionConfig['retries']['min_wait_ms'] ?? 100],
                    ['Timeout (s)', $connectionConfig['http_options']['timeout'] ?? 30],
                    ['Connect Timeout (s)', $connectionConfig['http_options']['connect_timeout'] ?? 10],
                ]
            );
        }
    }

    /**
     * Test the connection to OpenFGA.
     */
    protected function testConnection(OpenFgaManager $manager, ?string $connection): void
    {
        $this->info('Testing Connection:');
        $this->line('Attempting to connect to OpenFGA...');

        try {
            $startTime = microtime(true);
            
            // Try to perform a simple check operation
            $manager->connection($connection)->check('test:user', 'test', 'test:object');
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->info("✅ Connection successful! (Response time: {$duration}ms)");
        } catch (\Exception $e) {
            $this->error("❌ Connection failed!");
            $this->error("Error: " . $e->getMessage());
            
            if ($this->output->isVerbose()) {
                $this->newLine();
                $this->error("Stack trace:");
                $this->line($e->getTraceAsString());
            }
        }
    }

    /**
     * Display cache information.
     */
    protected function displayCacheInfo(): void
    {
        $this->info('Cache Configuration:');

        $cacheConfig = Config::get('openfga.cache');
        
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', $cacheConfig['enabled'] ? 'Yes' : 'No'],
                ['Store', $cacheConfig['store'] ?? 'default'],
                ['TTL (seconds)', $cacheConfig['ttl'] ?? 300],
                ['Prefix', $cacheConfig['prefix'] ?? 'openfga'],
            ]
        );

        if ($cacheConfig['enabled'] && $this->output->isVerbose()) {
            $cacheStore = Config::get("cache.stores.{$cacheConfig['store']}", Config::get('cache.stores.' . Config::get('cache.default')));
            if ($cacheStore) {
                $this->newLine();
                $this->line('Cache Store Details:');
                $this->line('  Driver: ' . ($cacheStore['driver'] ?? 'unknown'));
                
                if (isset($cacheStore['connection'])) {
                    $this->line('  Connection: ' . $cacheStore['connection']);
                }
            }
        }
    }

    /**
     * Display queue information.
     */
    protected function displayQueueInfo(): void
    {
        $this->info('Queue Configuration:');

        $queueConfig = Config::get('openfga.queue');
        
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', $queueConfig['enabled'] ? 'Yes' : 'No'],
                ['Connection', $queueConfig['connection'] ?? 'default'],
                ['Queue Name', $queueConfig['queue'] ?? 'openfga'],
            ]
        );

        if ($queueConfig['enabled'] && $this->output->isVerbose()) {
            $queueConnection = Config::get("queue.connections.{$queueConfig['connection']}", Config::get('queue.connections.' . Config::get('queue.default')));
            if ($queueConnection) {
                $this->newLine();
                $this->line('Queue Connection Details:');
                $this->line('  Driver: ' . ($queueConnection['driver'] ?? 'unknown'));
                
                if (isset($queueConnection['queue'])) {
                    $this->line('  Default Queue: ' . $queueConnection['queue']);
                }
            }
        }
    }

    /**
     * Mask sensitive values for display.
     */
    protected function maskValue(string $value): string
    {
        if ($value === 'Not set' || strlen($value) < 8) {
            return $value;
        }

        $visibleLength = min(4, intval(strlen($value) / 4));
        return substr($value, 0, $visibleLength) . str_repeat('*', strlen($value) - $visibleLength * 2) . substr($value, -$visibleLength);
    }
}