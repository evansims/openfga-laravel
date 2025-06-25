<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Cache;

use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\OpenFgaManager;

class WriteBehindCacheServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(WriteBehindCache::class, function ($app) {
            return new WriteBehindCache(
                $app->make('cache')->store(config('openfga.cache.write_behind_store')),
                $app->make('queue'),
                $app->make(OpenFgaManager::class),
                config('openfga.cache.write_behind_batch_size', 100),
                config('openfga.cache.write_behind_flush_interval', 5)
            );
        });
    }

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
     * Register periodic flush using scheduler
     */
    protected function registerPeriodicFlush(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        // This would typically be done in the app's console kernel
        // But we can provide a helper method
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            
            $schedule->call(function () {
                $cache = $this->app->make(WriteBehindCache::class);
                $cache->flush();
            })->everyMinute()->name('openfga-write-behind-flush')->withoutOverlapping();
        });
    }

    /**
     * Register shutdown flush
     */
    protected function registerShutdownFlush(): void
    {
        register_shutdown_function(function () {
            try {
                $cache = $this->app->make(WriteBehindCache::class);
                $pending = $cache->getPendingCount();
                
                if ($pending['total'] > 0) {
                    $cache->flush();
                }
            } catch (\Exception $e) {
                // Log but don't throw during shutdown
                \Log::error('Failed to flush write-behind cache on shutdown', [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}