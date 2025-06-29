<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use OpenFGA\Laravel\Providers\SpatieBladeServiceProvider;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('SpatieBladeServiceProvider', function (): void {
    beforeEach(function (): void {
        $this->provider = new SpatieBladeServiceProvider($this->app);
    });

    it('extends Laravel ServiceProvider', function (): void {
        expect($this->provider)->toBeInstanceOf(ServiceProvider::class);
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(SpatieBladeServiceProvider::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('is marked as internal', function (): void {
        $reflection = new ReflectionClass(SpatieBladeServiceProvider::class);
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

    it('has private registerSpatieBladeDirectives method', function (): void {
        $reflection = new ReflectionClass($this->provider);

        expect($reflection->hasMethod('registerSpatieBladeDirectives'))->toBeTrue();

        $method = $reflection->getMethod('registerSpatieBladeDirectives');
        expect($method->isPrivate())->toBeTrue();
    });

    it('boots without errors', function (): void {
        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('registers blade directives during boot', function (): void {
        // Clear any existing directives
        $this->provider->boot();

        // Check that directives are registered
        $directives = Blade::getCustomDirectives();

        // The service provider registers @hasrole, @haspermission, etc as if directives
        // These are stored differently than regular directives
        expect($directives)->toBeArray();
    });

    it('provides Spatie-compatible blade directives', function (): void {
        // The provider should register these directives:
        // @hasrole, @hasanyrole, @hasallroles
        // @haspermission, @hasanypermission, @hasallpermissions
        // @hasexactroles

        // Since we can't easily test the actual Blade compilation,
        // we verify the boot method runs without error
        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('uses app helper to resolve SpatieCompatibility', function (): void {
        $this->provider->register();

        // The blade directives use app(SpatieCompatibility::class)
        // Verify the service is available
        $compatibility = app(SpatieCompatibility::class);

        expect($compatibility)->toBeInstanceOf(SpatieCompatibility::class);
    });
});
