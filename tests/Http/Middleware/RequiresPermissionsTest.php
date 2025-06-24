<?php

declare(strict_types=1);

use OpenFGA\Laravel\Http\Middleware\{OpenFgaMiddleware, RequiresAllPermissions, RequiresAnyPermission, RequiresPermission};
use OpenFGA\Laravel\OpenFgaManager;

describe('Permission Middleware Tests', function (): void {
    it('tests middleware classes exist and can be loaded', function (): void {
        // Test that the middleware classes are properly configured
        expect(class_exists(RequiresAllPermissions::class))->toBeTrue();
        expect(class_exists(RequiresAnyPermission::class))->toBeTrue();
        expect(class_exists(RequiresPermission::class))->toBeTrue();
        expect(class_exists(OpenFgaMiddleware::class))->toBeTrue();
        expect(class_exists(OpenFgaManager::class))->toBeTrue();
    });
});
