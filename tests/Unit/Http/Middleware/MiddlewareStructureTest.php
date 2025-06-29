<?php

declare(strict_types=1);

use OpenFGA\Laravel\Http\Middleware\{LoadPermissions, OpenFgaMiddleware, RequiresAllPermissions, RequiresAnyPermission, RequiresPermission};
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\Traits\{ResolvesAuthorizationObject, ResolvesAuthorizationUser};

uses(TestCase::class);

describe('Middleware structure and composition', function (): void {
    it('verifies all middleware classes exist', function (): void {
        expect(class_exists(OpenFgaMiddleware::class))->toBeTrue();
        expect(class_exists(RequiresAllPermissions::class))->toBeTrue();
        expect(class_exists(RequiresAnyPermission::class))->toBeTrue();
        expect(class_exists(RequiresPermission::class))->toBeTrue();
        expect(class_exists(LoadPermissions::class))->toBeTrue();
    });

    it('verifies all middleware are final classes', function (): void {
        $classes = [
            OpenFgaMiddleware::class,
            RequiresAllPermissions::class,
            RequiresAnyPermission::class,
            RequiresPermission::class,
            LoadPermissions::class,
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            expect($reflection->isFinal())->toBeTrue();
        }
    });

    it('verifies middleware use authorization traits', function (): void {
        $middlewareWithTraits = [
            OpenFgaMiddleware::class,
            RequiresAllPermissions::class,
            RequiresAnyPermission::class,
            LoadPermissions::class,
        ];

        foreach ($middlewareWithTraits as $middleware) {
            $traits = class_uses($middleware);
            expect($traits)->toContain(ResolvesAuthorizationObject::class);
            expect($traits)->toContain(ResolvesAuthorizationUser::class);
        }
    });

    it('verifies middleware have handle methods', function (): void {
        $middlewares = [
            OpenFgaMiddleware::class,
            RequiresAllPermissions::class,
            RequiresAnyPermission::class,
            RequiresPermission::class,
            LoadPermissions::class,
        ];

        foreach ($middlewares as $middleware) {
            expect(method_exists($middleware, 'handle'))->toBeTrue();
        }
    });

    it('verifies OpenFgaMiddleware constructor parameters', function (): void {
        $reflection = new ReflectionClass(OpenFgaMiddleware::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('manager');
        expect($params[0]->getType()->getName())->toBe('OpenFGA\Laravel\Contracts\ManagerInterface');
    });

    it('verifies RequiresPermission constructor parameters', function (): void {
        $reflection = new ReflectionClass(RequiresPermission::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('middleware');
        expect($params[0]->getType()->getName())->toBe('OpenFGA\Laravel\Http\Middleware\OpenFgaMiddleware');
    });

    it('verifies RequiresAllPermissions constructor parameters', function (): void {
        $reflection = new ReflectionClass(RequiresAllPermissions::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('manager');
        expect($params[0]->getType()->getName())->toBe('OpenFGA\Laravel\Contracts\ManagerInterface');
    });

    it('verifies RequiresAnyPermission constructor parameters', function (): void {
        $reflection = new ReflectionClass(RequiresAnyPermission::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('manager');
        expect($params[0]->getType()->getName())->toBe('OpenFGA\Laravel\Contracts\ManagerInterface');
    });

    it('verifies LoadPermissions constructor parameters', function (): void {
        $reflection = new ReflectionClass(LoadPermissions::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('manager');
        expect($params[0]->getType()->getName())->toBe('OpenFGA\Laravel\Contracts\ManagerInterface');
    });

    it('verifies handle method signatures', function (): void {
        $middlewares = [
            OpenFgaMiddleware::class,
            RequiresAllPermissions::class,
            RequiresAnyPermission::class,
            RequiresPermission::class,
            LoadPermissions::class,
        ];

        foreach ($middlewares as $middleware) {
            $reflection = new ReflectionClass($middleware);
            $handle = $reflection->getMethod('handle');

            expect($handle->isPublic())->toBeTrue();

            $params = $handle->getParameters();
            expect(count($params))->toBeGreaterThanOrEqual(2);
            expect($params[0]->getName())->toBe('request');
            expect($params[1]->getName())->toBe('next');

            // Most middleware have variadic parameters for relations
            // RequiresPermission delegates all params so checking variadic isn't needed for all
        }
    });

    it('verifies authorization traits are properly used', function (): void {
        // Traits provide protected methods, not public ones
        // Let's verify the traits exist and are used correctly
        expect(trait_exists(ResolvesAuthorizationObject::class))->toBeTrue();
        expect(trait_exists(ResolvesAuthorizationUser::class))->toBeTrue();

        // Verify traits are used by the middleware
        $middlewareWithTraits = [
            OpenFgaMiddleware::class,
            RequiresAllPermissions::class,
            RequiresAnyPermission::class,
            LoadPermissions::class,
        ];

        foreach ($middlewareWithTraits as $middleware) {
            $traits = class_uses($middleware);
            expect($traits)->toContain(ResolvesAuthorizationObject::class);
            expect($traits)->toContain(ResolvesAuthorizationUser::class);

            // The trait methods are protected, so we can't check them directly
            // but we've already verified the traits are used
        }
    });

    it('validates middleware readonly properties', function (): void {
        // Several middleware are declared as readonly classes
        $readonlyClasses = [
            RequiresAllPermissions::class,
            RequiresAnyPermission::class,
            RequiresPermission::class,
            LoadPermissions::class,
        ];

        foreach ($readonlyClasses as $class) {
            $reflection = new ReflectionClass($class);

            // PHP 8.2+ readonly classes
            if (method_exists($reflection, 'isReadOnly')) {
                expect($reflection->isReadOnly())->toBeTrue();
            }
        }
    });
});
