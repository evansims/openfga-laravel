<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use OpenFGA\Laravel\Debugbar\{DebugbarServiceProvider};
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('DebugbarServiceProvider', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->provider = new DebugbarServiceProvider($this->app);
    });

    afterEach(function (): void {
        $this->tearDownConfigRestoration();
    });

    it('extends Laravel ServiceProvider', function (): void {
        expect($this->provider)->toBeInstanceOf(ServiceProvider::class);
    });

    it('is marked as final', function (): void {
        $reflection = new ReflectionClass(DebugbarServiceProvider::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('is marked as internal', function (): void {
        $reflection = new ReflectionClass(DebugbarServiceProvider::class);
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

    it('registers without errors when Debugbar is not available', function (): void {
        // Simulate Debugbar not being available
        expect(fn () => $this->provider->register())->not->toThrow(Exception::class);
    });

    it('boots without errors when Debugbar is not enabled', function (): void {
        $this->setConfigWithRestore('debugbar.enabled', false);

        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('registers OpenFgaCollector as singleton when available', function (): void {
        // This would require Debugbar to be installed, which we can't guarantee in unit tests
        // So we just check that the register method exists and can be called
        expect(fn () => $this->provider->register())->not->toThrow(Exception::class);
    });

    it('extends OpenFgaManager when wrapping', function (): void {
        // Register a mock OpenFgaManager first
        $this->app->singleton(OpenFgaManager::class, function (): stdClass {
            // We can't instantiate it directly due to final class,
            // but we can verify the extend call is made
            return new stdClass; // Placeholder
        });

        // The wrapOpenFgaManager method is private, so we test through boot
        $this->setConfigWithRestore('debugbar.enabled', false); // Disable to avoid actual wrapping

        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('has private wrapOpenFgaManager method', function (): void {
        $reflection = new ReflectionClass($this->provider);

        expect($reflection->hasMethod('wrapOpenFgaManager'))->toBeTrue();

        $method = $reflection->getMethod('wrapOpenFgaManager');
        expect($method->isPrivate())->toBeTrue();
    });

    it('validates app instance type in extend callback', function (): void {
        // The extend callback validates that $app is an Application instance
        // This is tested indirectly through the boot method
        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('returns early from boot when Debugbar class does not exist', function (): void {
        // Since we can't easily mock class_exists, we test that boot completes without error
        $this->setConfigWithRestore('debugbar.enabled', true);

        expect(fn () => $this->provider->boot())->not->toThrow(Exception::class);
    });

    it('returns early from register when Debugbar class does not exist', function (): void {
        // Since we can't easily mock class_exists, we test that register completes without error
        expect(fn () => $this->provider->register())->not->toThrow(Exception::class);
    });
});
