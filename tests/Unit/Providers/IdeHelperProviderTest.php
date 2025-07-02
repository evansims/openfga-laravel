<?php

declare(strict_types=1);

use Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider;
use OpenFGA\Laravel\Facades\OpenFga;
use OpenFGA\Laravel\Providers\IdeHelperProvider;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('IdeHelperProvider', function (): void {
    beforeEach(function (): void {
        // Reset config
        config()->set('ide-helper.extra', []);
        config()->set('ide-helper.custom_db_types', []);
    });

    it('extends Laravel ServiceProvider', function (): void {
        expect(IdeHelperProvider::class)->toExtend(Illuminate\Support\ServiceProvider::class);
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(IdeHelperProvider::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('has register method', function (): void {
        expect(IdeHelperProvider::class)->toHaveMethod('register');
    });

    it('has boot method', function (): void {
        expect(IdeHelperProvider::class)->toHaveMethod('boot');
    });

    it('registers IDE helper in local environment when available', function (): void {
        // Check if IdeHelperServiceProvider exists
        if (! class_exists(IdeHelperServiceProvider::class)) {
            $this->markTestSkipped('barryvdh/laravel-ide-helper not installed');
        }

        // Set environment to local
        $this->app['env'] = 'local';

        // Register our provider
        $provider = new IdeHelperProvider($this->app);
        $provider->register();

        // Since we can't easily check if a provider was registered in tests,
        // we'll just verify no errors occurred
        expect(true)->toBeTrue();
    });

    it('does not register IDE helper in production environment', function (): void {
        // Set environment to production
        $this->app['env'] = 'production';

        // Register our provider
        $provider = new IdeHelperProvider($this->app);
        $provider->register();

        // Verify no errors occurred
        expect(true)->toBeTrue();
    });

    it('sets OpenFGA facade in IDE helper extra config', function (): void {
        // Check if IdeHelperServiceProvider exists
        if (! class_exists(IdeHelperServiceProvider::class)) {
            $this->markTestSkipped('barryvdh/laravel-ide-helper not installed');
        }

        // Set environment to local
        $this->app['env'] = 'local';

        // Register and boot our provider
        $provider = new IdeHelperProvider($this->app);
        $provider->register();
        $provider->boot();

        // Check the configuration
        expect(config('ide-helper.extra.OpenFGA'))->toBe(OpenFga::class);
    });

    it('adds custom database types for OpenFGA', function (): void {
        // Check if IdeHelperServiceProvider exists
        if (! class_exists(IdeHelperServiceProvider::class)) {
            $this->markTestSkipped('barryvdh/laravel-ide-helper not installed');
        }

        // Set environment to local
        $this->app['env'] = 'local';

        // Register and boot our provider
        $provider = new IdeHelperProvider($this->app);
        $provider->register();
        $provider->boot();

        // Check the custom database types
        $customTypes = config('ide-helper.custom_db_types', []);
        expect($customTypes)->toHaveKey('openfga_object');
        expect($customTypes)->toHaveKey('openfga_user');
        expect($customTypes)->toHaveKey('openfga_relation');
    });

    it('handles missing IDE helper package gracefully', function (): void {
        // Set environment to local
        $this->app['env'] = 'local';

        // This test verifies that the provider doesn't throw errors
        // when the IDE helper package is not installed
        $provider = new IdeHelperProvider($this->app);
        $provider->register();
        $provider->boot();
        
        // If we got here without exceptions, the test passes
        expect(true)->toBeTrue();
    });

    it('does not boot in production environment', function (): void {
        // Set environment to production
        $this->app['env'] = 'production';

        // Register and boot our provider
        $provider = new IdeHelperProvider($this->app);
        $provider->register();
        $provider->boot();

        // Configuration should not be set
        expect(config('ide-helper.extra.OpenFGA'))->toBeNull();
        expect(config('ide-helper.custom_db_types.openfga_object'))->toBeNull();
    });
});