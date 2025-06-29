<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use OpenFGA\Laravel\Http\Middleware\{SpatiePermissionMiddleware, SpatieRoleMiddleware};
use OpenFGA\Laravel\Providers\SpatieCompatibilityServiceProvider;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('SpatieCompatibilityServiceProvider', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->provider = new SpatieCompatibilityServiceProvider($this->app);
    });

    afterEach(function (): void {
        $this->tearDownConfigRestoration();
    });

    it('extends Laravel ServiceProvider', function (): void {
        expect($this->provider)->toBeInstanceOf(ServiceProvider::class);
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(SpatieCompatibilityServiceProvider::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('is marked as internal', function (): void {
        $reflection = new ReflectionClass(SpatieCompatibilityServiceProvider::class);
        $docComment = $reflection->getDocComment();

        expect($docComment)->toContain('@internal');
    });

    it('has register method', function (): void {
        expect(method_exists($this->provider, 'register'))->toBeTrue();

        $reflection = new ReflectionClass($this->provider);
        $method = $reflection->getMethod('register');

        expect($method->isPublic())->toBeTrue();
    });

    it('has boot method', function (): void {
        expect(method_exists($this->provider, 'boot'))->toBeTrue();

        $reflection = new ReflectionClass($this->provider);
        $method = $reflection->getMethod('boot');

        expect($method->isPublic())->toBeTrue();
    });

    it('registers SpatieCompatibility as singleton', function (): void {
        $this->provider->register();

        expect($this->app->bound(SpatieCompatibility::class))->toBeTrue();

        // Verify it's a singleton
        $instance1 = $this->app->make(SpatieCompatibility::class);
        $instance2 = $this->app->make(SpatieCompatibility::class);

        expect($instance1)->toBe($instance2);
    });

    it('merges config during registration', function (): void {
        expect(fn () => $this->provider->register())->not->toThrow(Exception::class);

        // Config should be available after registration
        expect(config()->has('spatie-compatibility'))->toBeTrue();
    });

    it('does not boot when disabled', function (): void {
        $this->setConfigWithRestore('spatie-compatibility.enabled', false);

        // Should return early without errors
        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('boots when enabled', function (): void {
        $this->setConfigWithRestore('spatie-compatibility.enabled', true);

        // Mock router to avoid actual middleware registration
        $mockRouter = mock(Router::class);
        $mockRouter->shouldReceive('aliasMiddleware')->andReturnSelf();
        $this->app->instance('router', $mockRouter);

        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('has private boot methods', function (): void {
        $reflection = new ReflectionClass($this->provider);

        expect($reflection->hasMethod('bootPublishing'))->toBeTrue();
        expect($reflection->hasMethod('bootMiddleware'))->toBeTrue();
        expect($reflection->hasMethod('bootBladeDirectives'))->toBeTrue();

        $publishingMethod = $reflection->getMethod('bootPublishing');
        expect($publishingMethod->isPrivate())->toBeTrue();

        $middlewareMethod = $reflection->getMethod('bootMiddleware');
        expect($middlewareMethod->isPrivate())->toBeTrue();

        $directivesMethod = $reflection->getMethod('bootBladeDirectives');
        expect($directivesMethod->isPrivate())->toBeTrue();
    });

    it('loads permission mappings from config', function (): void {
        $this->setConfigWithRestore('spatie-compatibility.permission_mappings', [
            'edit posts' => 'editor',
            'delete posts' => 'admin',
        ]);

        $this->provider->register();

        $compatibility = $this->app->make(SpatieCompatibility::class);
        expect($compatibility)->toBeInstanceOf(SpatieCompatibility::class);
    });

    it('loads role mappings from config', function (): void {
        $this->setConfigWithRestore('spatie-compatibility.role_mappings', [
            'super-admin' => 'admin',
            'editor' => 'editor',
        ]);

        $this->provider->register();

        $compatibility = $this->app->make(SpatieCompatibility::class);
        expect($compatibility)->toBeInstanceOf(SpatieCompatibility::class);
    });

    it('handles invalid permission mappings gracefully', function (): void {
        $this->setConfigWithRestore('spatie-compatibility.permission_mappings', [
            123 => 'editor', // Invalid key
            'valid' => 456, // Invalid value
            'good' => 'relation', // Valid
        ]);

        expect(fn () => $this->provider->register())->not->toThrow(Exception::class);
    });

    it('handles invalid role mappings gracefully', function (): void {
        $this->setConfigWithRestore('spatie-compatibility.role_mappings', [
            0 => 'admin', // Numeric key (invalid for role name)
            'valid' => null, // Invalid value
            'good' => 'role', // Valid
        ]);

        expect(fn () => $this->provider->register())->not->toThrow(Exception::class);
    });

    it('registers middleware aliases when enabled', function (): void {
        $this->setConfigWithRestore('spatie-compatibility.enabled', true);

        $mockRouter = mock(Router::class);
        $mockRouter->shouldReceive('aliasMiddleware')
            ->with('role', SpatieRoleMiddleware::class)
            ->once()
            ->andReturnSelf();
        $mockRouter->shouldReceive('aliasMiddleware')
            ->with('permission', SpatiePermissionMiddleware::class)
            ->once()
            ->andReturnSelf();

        $this->app->instance('router', $mockRouter);

        $this->provider->boot();
    });
});
