<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Debugbar;

use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use OpenFGA\Laravel\OpenFgaManager;
use Override;

use function is_object;

/**
 * @internal
 */
final class DebugbarServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     */
    public function boot(): void
    {
        // Only boot if Debugbar is available and enabled
        /** @var bool $debugbarEnabled */
        $debugbarEnabled = config('debugbar.enabled', false);

        if (! class_exists(Debugbar::class) || ! $debugbarEnabled) {
            return;
        }

        // Add the collector to Debugbar
        if ($this->app->bound('debugbar') && class_exists('DebugBar\\DataCollector\\DataCollector')) {
            /** @var mixed $debugbar */
            $debugbar = $this->app->make('debugbar');

            if (is_object($debugbar) && method_exists($debugbar, 'addCollector') && class_exists(OpenFgaCollector::class)) {
                /** @var class-string<OpenFgaCollector> $collectorClass */
                $collectorClass = OpenFgaCollector::class;
                $collector = $this->app->make($collectorClass);

                $debugbar->addCollector($collector);
            }
        }

        // Wrap the OpenFgaManager to collect metrics
        $this->wrapOpenFgaManager();
    }

    /**
     * Register services.
     */
    #[Override]
    public function register(): void
    {
        // Only register if Debugbar is available
        if (! class_exists(Debugbar::class)) {
            return;
        }

        if (class_exists('DebugBar\\DataCollector\\DataCollector') && class_exists(OpenFgaCollector::class)) {
            $this->app->singleton(OpenFgaCollector::class);
        }
    }

    /**
     * Wrap OpenFgaManager methods to collect metrics.
     *
     * @throws InvalidArgumentException
     */
    private function wrapOpenFgaManager(): void
    {
        $this->app->extend(OpenFgaManager::class, static function (mixed $manager, mixed $app): mixed {
            if (! $app instanceof Application) {
                throw new InvalidArgumentException('Expected Application instance');
            }

            if (! $manager instanceof OpenFgaManager) {
                throw new InvalidArgumentException('Expected OpenFgaManager instance');
            }

            if (! class_exists('DebugBar\\DataCollector\\DataCollector') || ! class_exists(OpenFgaCollector::class)) {
                return $manager;
            }

            /** @var class-string<OpenFgaCollector> $collectorClass */
            $collectorClass = OpenFgaCollector::class;
            $collector = $app->make($collectorClass);

            return new DebugbarOpenFgaManager($manager, $collector);
        });
    }
}
