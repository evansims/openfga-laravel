<?php

declare(strict_types=1);

use OpenFGA\Laravel\Http\Middleware\{SpatiePermissionMiddleware, SpatieRoleMiddleware};
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('Spatie compatibility middleware', function (): void {
    it('verifies Spatie middleware classes exist', function (): void {
        expect(class_exists(SpatiePermissionMiddleware::class))->toBeTrue();
        expect(class_exists(SpatieRoleMiddleware::class))->toBeTrue();
    });

    it('verifies Spatie middleware are final classes', function (): void {
        $reflection = new ReflectionClass(SpatiePermissionMiddleware::class);
        expect($reflection->isFinal())->toBeTrue();

        $reflection = new ReflectionClass(SpatieRoleMiddleware::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('verifies Spatie middleware have handle methods', function (): void {
        expect(method_exists(SpatiePermissionMiddleware::class, 'handle'))->toBeTrue();
        expect(method_exists(SpatieRoleMiddleware::class, 'handle'))->toBeTrue();
    });

    it('verifies SpatiePermissionMiddleware constructor', function (): void {
        $reflection = new ReflectionClass(SpatiePermissionMiddleware::class);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('compatibility');
        expect($params[0]->getType()->getName())->toBe('OpenFGA\Laravel\Compatibility\SpatieCompatibility');
    });

    it('verifies SpatieRoleMiddleware constructor', function (): void {
        $reflection = new ReflectionClass(SpatieRoleMiddleware::class);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('compatibility');
        expect($params[0]->getType()->getName())->toBe('OpenFGA\Laravel\Compatibility\SpatieCompatibility');
    });

    it('verifies handle method signatures for Spatie middleware', function (): void {
        // SpatiePermissionMiddleware handle
        $reflection = new ReflectionClass(SpatiePermissionMiddleware::class);
        $handle = $reflection->getMethod('handle');

        expect($handle->isPublic())->toBeTrue();

        $params = $handle->getParameters();
        expect(count($params))->toBeGreaterThanOrEqual(3);
        expect($params[0]->getName())->toBe('request');
        expect($params[1]->getName())->toBe('next');
        expect($params[2]->getName())->toBe('permission');

        // SpatieRoleMiddleware handle
        $reflection = new ReflectionClass(SpatieRoleMiddleware::class);
        $handle = $reflection->getMethod('handle');

        expect($handle->isPublic())->toBeTrue();

        $params = $handle->getParameters();
        expect(count($params))->toBeGreaterThanOrEqual(3);
        expect($params[0]->getName())->toBe('request');
        expect($params[1]->getName())->toBe('next');
        expect($params[2]->getName())->toBe('role');
    });

    it('verifies Spatie middleware structure', function (): void {
        // Spatie middleware don't use the authorization traits
        // They use the SpatieCompatibility class instead
        $reflection = new ReflectionClass(SpatiePermissionMiddleware::class);
        expect($reflection->isReadOnly())->toBeTrue();

        $reflection = new ReflectionClass(SpatieRoleMiddleware::class);
        expect($reflection->isReadOnly())->toBeTrue();

        // They don't use authorization traits directly
        $traits = class_uses(SpatiePermissionMiddleware::class);
        expect($traits)->toBe([]);

        $traits = class_uses(SpatieRoleMiddleware::class);
        expect($traits)->toBe([]);
    });

    it('verifies Spatie middleware readonly status', function (): void {
        $reflection = new ReflectionClass(SpatiePermissionMiddleware::class);

        if (method_exists($reflection, 'isReadOnly')) {
            expect($reflection->isReadOnly())->toBeTrue();
        }

        $reflection = new ReflectionClass(SpatieRoleMiddleware::class);

        if (method_exists($reflection, 'isReadOnly')) {
            expect($reflection->isReadOnly())->toBeTrue();
        }
    });
});
