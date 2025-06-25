<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Debugbar;

use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\OpenFgaManager;

class DebugbarServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Only register if Debugbar is available
        if (! class_exists(Debugbar::class)) {
            return;
        }

        $this->app->singleton(OpenFgaCollector::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Only boot if Debugbar is available and enabled
        if (! class_exists(Debugbar::class) || ! config('debugbar.enabled')) {
            return;
        }

        // Add the collector to Debugbar
        if ($this->app->bound('debugbar')) {
            $debugbar = $this->app->make('debugbar');
            $collector = $this->app->make(OpenFgaCollector::class);
            $debugbar->addCollector($collector);
        }

        // Wrap the OpenFgaManager to collect metrics
        $this->wrapOpenFgaManager();
    }

    /**
     * Wrap OpenFgaManager methods to collect metrics
     */
    protected function wrapOpenFgaManager(): void
    {
        $this->app->extend(OpenFgaManager::class, function ($manager, $app) {
            return new DebugbarOpenFgaManager(
                $manager,
                $app->make(OpenFgaCollector::class)
            );
        });
    }
}