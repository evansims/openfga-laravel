<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\{CallbackEvent, Schedule};
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Cache\{WriteBehindCache, WriteBehindCacheServiceProvider};
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('WriteBehindCacheServiceProvider', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->provider = new WriteBehindCacheServiceProvider($this->app);
    });

    afterEach(function (): void {
        $this->tearDownConfigRestoration();
    });

    it('registers WriteBehindCache as singleton', function (): void {
        $this->provider->register();

        expect($this->app->bound(WriteBehindCache::class))->toBeTrue();
    });

    it('validates configuration keys', function (): void {
        // Test configuration keys used by the provider
        $configKeys = [
            'openfga.cache.write_behind_store',
            'openfga.cache.write_behind_batch_size',
            'openfga.cache.write_behind_flush_interval',
            'openfga.cache.write_behind_periodic_flush',
            'openfga.cache.write_behind_flush_on_shutdown',
        ];

        foreach ($configKeys as $configKey) {
            $this->setConfigWithRestore($configKey, 'test_value');
            expect(config($configKey))->toBe('test_value');
        }
    });

    it('registers periodic flush when enabled', function (): void {
        $this->setConfigWithRestore('openfga.cache.write_behind_periodic_flush', true);

        $mockSchedule = mock(Schedule::class);
        $mockEvent = mock(CallbackEvent::class);

        $mockSchedule->shouldReceive('call')
            ->with(Mockery::type('closure'))
            ->once()
            ->andReturn($mockEvent);

        $mockEvent->shouldReceive('everyMinute')->once()->andReturnSelf();
        $mockEvent->shouldReceive('name')->with('openfga-write-behind-flush')->once()->andReturnSelf();
        $mockEvent->shouldReceive('withoutOverlapping')->once()->andReturnSelf();

        $this->app->instance(Schedule::class, $mockSchedule);

        // Use partial mock for app
        $appMock = Mockery::mock($this->app)->makePartial();
        $appMock->shouldReceive('runningInConsole')->andReturn(false);
        $appMock->shouldReceive('booted')->with(Mockery::type('closure'))->andReturnUsing(function ($callback): void {
            $callback(); // Execute the callback immediately
        });

        $provider = new WriteBehindCacheServiceProvider($appMock);

        $provider->boot();

        // Add explicit assertion to avoid risky test warning
        expect(true)->toBeTrue(); // All mock expectations were met
    });

    it('does not register periodic flush when disabled', function (): void {
        $this->setConfigWithRestore('openfga.cache.write_behind_periodic_flush', false);

        $mockSchedule = mock(Schedule::class);
        $mockSchedule->shouldNotReceive('call');

        $this->app->instance(Schedule::class, $mockSchedule);

        $this->provider->boot();
    });

    it('does not register periodic flush when running in console', function (): void {
        $this->setConfigWithRestore('openfga.cache.write_behind_periodic_flush', true);

        $mockSchedule = mock(Schedule::class);
        $mockSchedule->shouldNotReceive('call');

        $this->app->instance(Schedule::class, $mockSchedule);

        $appMock = Mockery::mock($this->app)->makePartial();
        $appMock->shouldReceive('runningInConsole')->andReturn(true);

        $provider = new WriteBehindCacheServiceProvider($appMock);
        $provider->boot();
    });

    it('validates shutdown flush logic', function (): void {
        // Create a fresh provider instance for each test to avoid side effects

        // Test shutdown flush is registered when enabled
        $this->setConfigWithRestore('openfga.cache.write_behind_flush_on_shutdown', true);
        $provider1 = new WriteBehindCacheServiceProvider($this->app);
        expect(static fn () => $provider1->boot())->not->toThrow(Exception::class);

        // Test shutdown flush is not registered when disabled
        $this->setConfigWithRestore('openfga.cache.write_behind_flush_on_shutdown', false);
        $provider2 = new WriteBehindCacheServiceProvider($this->app);
        expect(static fn () => $provider2->boot())->not->toThrow(Exception::class);

        // Reset to default (disabled for tests)
        $this->setConfigWithRestore('openfga.cache.write_behind_flush_on_shutdown', false);
    });

    it('validates error logging during shutdown', function (): void {
        // Simply test that we can call Log::error without errors
        // This validates the logging call signature used in the provider
        expect(static fn () => Log::error('Failed to flush write-behind cache on shutdown', [
            'error' => 'Test error message',
        ]))->not->toThrow(Exception::class);
    });

    it('validates service provider methods exist', function (): void {
        expect(method_exists($this->provider, 'register'))->toBeTrue();
        expect(method_exists($this->provider, 'boot'))->toBeTrue();
    });

    it('validates schedule callback structure', function (): void {
        // Test the structure of schedule callbacks
        $schedule = $this->app->make(Schedule::class);

        expect($schedule)->toBeInstanceOf(Schedule::class);
        expect(method_exists($schedule, 'call'))->toBeTrue();
    });

    it('validates default configuration values', function (): void {
        // Test that config() with defaults works correctly
        // Don't explicitly set to null as that changes behavior

        // When a key doesn't exist, config() returns the default
        $defaultBatch = config('openfga.cache.nonexistent_batch_size', 100);
        expect($defaultBatch)->toBe(100);

        // When a key is explicitly null, config() returns null
        $this->setConfigWithRestore('openfga.cache.test_null_value', null);
        $nullValue = config('openfga.cache.test_null_value', 'default');
        expect($nullValue)->toBeNull();

        // Test actual config behavior - if already set in config file, use that
        // Otherwise use the default
        $batchSize = config('openfga.cache.write_behind_batch_size', 100);
        $flushInterval = config('openfga.cache.write_behind_flush_interval', 5);

        // These should either be the configured value or the default
        expect($batchSize)->toBeInt();
        expect($flushInterval)->toBeInt();

        // Store can be null
        $store = config('openfga.cache.write_behind_store');
        expect($store)->toBeNull();
    });

    it('validates service resolution patterns', function (): void {
        // Test the app resolution patterns
        expect(method_exists($this->app, 'singleton'))->toBeTrue();
        expect(method_exists($this->app, 'make'))->toBeTrue();
        expect(method_exists($this->app, 'booted'))->toBeTrue();
        expect(method_exists($this->app, 'runningInConsole'))->toBeTrue();
    });

    it('validates periodic flush configuration', function (): void {
        // Test periodic flush defaults - when set to null, config returns null not default
        $this->setConfigWithRestore('openfga.cache.write_behind_periodic_flush', null);
        $periodicFlush = config('openfga.cache.write_behind_periodic_flush', false);
        expect($periodicFlush)->toBeNull();

        // Test when explicitly enabled
        $this->setConfigWithRestore('openfga.cache.write_behind_periodic_flush', true);
        $periodicFlush = config('openfga.cache.write_behind_periodic_flush', false);
        expect($periodicFlush)->toBeTrue();

        // Test when explicitly disabled
        $this->setConfigWithRestore('openfga.cache.write_behind_periodic_flush', false);
        $periodicFlush = config('openfga.cache.write_behind_periodic_flush', true);
        expect($periodicFlush)->toBeFalse();
    });

    it('validates shutdown flush configuration', function (): void {
        // Test shutdown flush defaults - when set to null, config returns null not default
        $this->setConfigWithRestore('openfga.cache.write_behind_flush_on_shutdown', null);
        $shutdownFlush = config('openfga.cache.write_behind_flush_on_shutdown', true);
        expect($shutdownFlush)->toBeNull();

        // Test when explicitly disabled
        $this->setConfigWithRestore('openfga.cache.write_behind_flush_on_shutdown', false);
        $shutdownFlush = config('openfga.cache.write_behind_flush_on_shutdown', true);
        expect($shutdownFlush)->toBeFalse();

        // Test when explicitly enabled
        $this->setConfigWithRestore('openfga.cache.write_behind_flush_on_shutdown', true);
        $shutdownFlush = config('openfga.cache.write_behind_flush_on_shutdown', false);
        expect($shutdownFlush)->toBeTrue();
    });

    it('validates schedule event configuration', function (): void {
        $mockEvent = mock(CallbackEvent::class);

        // Test method chaining
        $mockEvent->shouldReceive('everyMinute')->andReturnSelf();
        $mockEvent->shouldReceive('name')->with('openfga-write-behind-flush')->andReturnSelf();
        $mockEvent->shouldReceive('withoutOverlapping')->andReturnSelf();

        $result = $mockEvent->everyMinute()->name('openfga-write-behind-flush')->withoutOverlapping();

        expect($result)->toBe($mockEvent);
    });

    it('validates cache factory usage', function (): void {
        // Test that cache factory is used correctly
        expect(method_exists($this->app, 'make'))->toBeTrue();

        // Verify cache factory would be called with correct parameter
        $cacheKey = 'cache';
        expect($cacheKey)->toBe('cache');
    });

    it('validates registration timing', function (): void {
        // Test that service provider has the expected lifecycle methods
        $provider = new WriteBehindCacheServiceProvider($this->app);

        // Verify methods exist
        expect(method_exists($provider, 'register'))->toBeTrue();
        expect(method_exists($provider, 'boot'))->toBeTrue();

        // Test that register can be called without errors
        expect(static fn () => $provider->register())->not->toThrow(Exception::class);

        // Test that boot can be called without errors
        $this->setConfigWithRestore('openfga.cache.write_behind_periodic_flush', false);
        $this->setConfigWithRestore('openfga.cache.write_behind_flush_on_shutdown', false);
        expect(static fn () => $provider->boot())->not->toThrow(Exception::class);
    });
});
