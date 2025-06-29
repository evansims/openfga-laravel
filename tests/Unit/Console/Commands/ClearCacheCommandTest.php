<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Console\Commands;

use Mockery;
use OpenFGA\Laravel\Cache\CacheWarmer;
use OpenFGA\Laravel\Console\Commands\ClearCacheCommand;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('ClearCacheCommand', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        // Create mock manager first
        $mockManager = Mockery::mock(ManagerInterface::class);
        $this->app->instance(ManagerInterface::class, $mockManager);

        // Create a partial mock of the final class
        $this->mockWarmer = Mockery::mock(new CacheWarmer(
            manager: $mockManager,
            config: [],
        ));
        $this->app->instance(CacheWarmer::class, $this->mockWarmer);

        // Enable cache by default for tests
        $this->setConfigWithRestore('openfga.cache.enabled', true);
    });

    afterEach(function (): void {
        Mockery::close();
        $this->tearDownConfigRestoration();
    });

    it('cancels when all confirmation denied', function (): void {
        $this->artisan('openfga:cache:clear', ['--all' => true])
            ->expectsConfirmation('Are you sure you want to clear all OpenFGA cache entries?', 'no')
            ->assertSuccessful();
    });

    it('clears all cache with confirmation', function (): void {
        $this->mockWarmer->shouldReceive('invalidate')
            ->once()
            ->with(null, null, null)
            ->andReturn(150);

        $this->artisan('openfga:cache:clear', ['--all' => true])
            ->expectsConfirmation('Are you sure you want to clear all OpenFGA cache entries?', 'yes')
            ->expectsOutput('Clearing OpenFGA cache...')
            ->expectsOutput('✅ Cleared 150 cache entries.')
            ->assertSuccessful();
    });

    it('clears cache for specific object', function (): void {
        $this->mockWarmer->shouldReceive('invalidate')
            ->once()
            ->with(null, null, 'document:456')
            ->andReturn(10);

        $this->artisan('openfga:cache:clear', ['--object' => 'document:456'])
            ->expectsOutput('Clearing OpenFGA cache...')
            ->expectsOutput('✅ Cleared 10 cache entries.')
            ->assertSuccessful();
    });

    it('clears cache for specific relation', function (): void {
        $this->mockWarmer->shouldReceive('invalidate')
            ->once()
            ->with(null, 'viewer', null)
            ->andReturn(50);

        $this->artisan('openfga:cache:clear', ['--relation' => 'viewer'])
            ->expectsOutput('Clearing OpenFGA cache...')
            ->expectsOutput('✅ Cleared 50 cache entries.')
            ->assertSuccessful();
    });

    it('clears cache for specific user', function (): void {
        $this->mockWarmer->shouldReceive('invalidate')
            ->once()
            ->with('user:123', null, null)
            ->andReturn(25);

        $this->artisan('openfga:cache:clear', ['--user' => 'user:123'])
            ->expectsOutput('Clearing OpenFGA cache...')
            ->expectsOutput('✅ Cleared 25 cache entries.')
            ->assertSuccessful();
    });

    it('clears cache with multiple filters', function (): void {
        $this->mockWarmer->shouldReceive('invalidate')
            ->once()
            ->with('user:123', 'viewer', 'document:456')
            ->andReturn(1);

        $this->artisan('openfga:cache:clear', [
            '--user' => 'user:123',
            '--relation' => 'viewer',
            '--object' => 'document:456',
        ])
            ->expectsOutput('Clearing OpenFGA cache...')
            ->expectsOutput('✅ Cleared 1 cache entries.')
            ->assertSuccessful();
    });

    it('fails when cache is disabled', function (): void {
        $this->setConfigWithRestore('openfga.cache.enabled', false);

        $this->artisan('openfga:cache:clear', ['--all' => true])
            ->expectsOutput('Cache is not enabled. Enable it in config/openfga.php')
            ->assertExitCode(1);
    });

    it('fails when no options specified', function (): void {
        $this->artisan('openfga:cache:clear')
            ->expectsOutput('Please specify what to clear or use --all flag.')
            ->assertExitCode(1);
    });

    it('handles non string option values', function (): void {
        $this->mockWarmer->shouldReceive('invalidate')
            ->once()
            ->with(null, null, null)
            ->andReturn(100);

        // Simulate non-string values (though Laravel typically converts them)
        $this->artisan('openfga:cache:clear', [
            '--user' => '',
            '--relation' => '',
            '--object' => '',
            '--all' => true,
        ])
            ->expectsConfirmation('Are you sure you want to clear all OpenFGA cache entries?', 'yes')
            ->expectsOutput('Clearing OpenFGA cache...')
            ->expectsOutput('✅ Cleared 100 cache entries.')
            ->assertSuccessful();
    });

    it('has correct signature', function (): void {
        $command = new ClearCacheCommand;
        $command->setLaravel($this->app);

        expect($command->getName())->toBe('openfga:cache:clear');
        expect($command->getDescription())->toContain('Clear the OpenFGA permission cache');

        $definition = $command->getDefinition();

        // Check options
        expect($definition->hasOption('user'))->toBeTrue();
        expect($definition->hasOption('relation'))->toBeTrue();
        expect($definition->hasOption('object'))->toBeTrue();
        expect($definition->hasOption('all'))->toBeTrue();
    });

    it('warns when no entries cleared', function (): void {
        $this->mockWarmer->shouldReceive('invalidate')
            ->once()
            ->with(null, null, null)
            ->andReturn(0);

        $this->artisan('openfga:cache:clear', ['--all' => true])
            ->expectsConfirmation('Are you sure you want to clear all OpenFGA cache entries?', 'yes')
            ->expectsOutput('Clearing OpenFGA cache...')
            ->expectsOutput('No cache entries were cleared (cache store may not support pattern deletion).')
            ->assertSuccessful();
    });
});
