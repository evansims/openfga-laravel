<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Cache;

use Exception;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Log;
use OpenFGA\Laravel\OpenFgaManager;
use Override;

final class WriteBehindCacheServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register periodic flush if configured
        if (config('openfga.cache.write_behind_periodic_flush', false)) {
            $this->registerPeriodicFlush();
        }

        // Register shutdown flush
        if (config('openfga.cache.write_behind_flush_on_shutdown', true)) {
            $this->registerShutdownFlush();
        }
    }

    /**
     * Register services.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(WriteBehindCache::class, static fn ($app): WriteBehindCache => new WriteBehindCache(
            $app->make('cache')->store(config('openfga.cache.write_behind_store')),
            $app->make('queue'),
            $app->make(OpenFgaManager::class),
            config('openfga.cache.write_behind_batch_size', 100),
            config('openfga.cache.write_behind_flush_interval', 5),
        ));
    }

    /**
     * Register periodic flush using scheduler.
     */
    protected function registerPeriodicFlush(): void
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
    protected function registerShutdownFlush(): void
    {
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
