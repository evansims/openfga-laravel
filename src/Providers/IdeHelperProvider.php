<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Providers;

use Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Facades\OpenFga;
use Override;

use function is_array;

/**
 * IDE Helper provider for OpenFGA Laravel package.
 *
 * This provider integrates with barryvdh/laravel-ide-helper to generate
 * IDE helper files for better autocompletion and static analysis support.
 *
 * @internal
 */
final class IdeHelperProvider extends ServiceProvider
{
    /**
     * Bootstrap the service provider.
     */
    public function boot(): void
    {
        if ('local' !== $this->app->environment() || ! class_exists(IdeHelperServiceProvider::class)) {
            return;
        }

        // Register OpenFGA facade for IDE helper generation
        config()->set('ide-helper.extra', [
            'OpenFGA' => OpenFga::class,
        ]);

        // Add OpenFGA specific model cast types
        /** @var mixed $existingTypes */
        $existingTypes = config('ide-helper.custom_db_types', []);
        $customTypes = is_array($existingTypes) ? $existingTypes : [];

        config()->set('ide-helper.custom_db_types', array_merge(
            $customTypes,
            [
                'openfga_object' => 'string',
                'openfga_user' => 'string',
                'openfga_relation' => 'string',
            ],
        ));
    }

    /**
     * Register the service provider.
     */
    #[Override]
    public function register(): void
    {
        if ('local' === $this->app->environment() && class_exists(IdeHelperServiceProvider::class)) {
            $this->app->register(IdeHelperServiceProvider::class);
        }
    }
}
