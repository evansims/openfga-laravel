<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Cache;

use Exception;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\OpenFgaManager;
use Override;

/**
 * @internal
 */
final class WriteBehindCacheServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register periodic flush if configured
        /** @var bool $periodicFlush */
        $periodicFlush = config('openfga.cache.write_behind_periodic_flush', false);

        if ($periodicFlush) {
            $this->registerPeriodicFlush();
        }

        // Register shutdown flush
        /** @var bool $flushOnShutdown */
        $flushOnShutdown = config('openfga.cache.write_behind_flush_on_shutdown', true);

        if ($flushOnShutdown) {
            $this->registerShutdownFlush();
        }
    }

    /**
     * Register services.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(WriteBehindCache::class, static function (Application $app): WriteBehindCache {
            /** @var Factory $cacheFactory */
            $cacheFactory = $app->make('cache');

            /** @var string|null $store */
            $store = config('openfga.cache.write_behind_store');

            $manager = $app->make(OpenFgaManager::class);

            /** @var int $batchSize */
            $batchSize = config('openfga.cache.write_behind_batch_size', 100);

            /** @var int $flushInterval */
            $flushInterval = config('openfga.cache.write_behind_flush_interval', 5);

            /** @var bool $useQueue */
            $useQueue = config('openfga.queue.enabled', false);

            /** @var string|null $queueConnection */
            $queueConnection = config('openfga.queue.connection');

            /** @var string $queueName */
            $queueName = config('openfga.queue.queue', 'openfga');

            return new WriteBehindCache(
                $cacheFactory->store($store),
                $manager,
                $batchSize,
                $flushInterval,
                $useQueue,
                $queueConnection,
                $queueName,
            );
        });
    }

    /**
     * Register periodic flush using scheduler.
     */
    private function registerPeriodicFlush(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        // This would typically be done in the app's console kernel
        // But we can provide a helper method
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            $schedule->call(function (): void {
                $cache = $this->app->make(WriteBehindCache::class);
                $cache->flush();
            })->everyMinute()->name('openfga-write-behind-flush')->withoutOverlapping();
        });
    }

    /**
     * Register shutdown flush.
     */
    private function registerShutdownFlush(): void
    {
        // Skip shutdown handler in testing environment to prevent exit code 255
        if ('testing' === $this->app->environment()) {
            return;
        }

        register_shutdown_function(function (): void {
            try {
                $cache = $this->app->make(WriteBehindCache::class);
                $pending = $cache->getPendingCount();

                if (0 < $pending['total']) {
                    $cache->flush();
                }
            } catch (Exception $exception) {
                // Log but don't throw during shutdown
                Log::error('Failed to flush write-behind cache on shutdown', [
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }
}
