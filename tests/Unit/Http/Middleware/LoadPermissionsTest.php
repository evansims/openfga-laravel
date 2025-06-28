<?php

declare(strict_types=1);

use OpenFGA\Laravel\Http\Middleware\LoadPermissions;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Testing\FakesOpenFga;

describe('LoadPermissions', function (): void {
    it('tests middleware class exists and can be loaded', function (): void {
        expect(class_exists(LoadPermissions::class))->toBeTrue();
        expect(class_exists(OpenFgaManager::class))->toBeTrue();
        expect(trait_exists(FakesOpenFga::class))->toBeTrue();
    });
});
