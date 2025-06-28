<?php

declare(strict_types=1);

use OpenFGA\Laravel\Authorization\OpenFgaGate;
use OpenFGA\Laravel\Contracts\{AuthorizableUser, AuthorizationObject, AuthorizationType, AuthorizationUser, AuthorizationUserId, ManagerInterface};
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Testing\FakesOpenFga;
use OpenFGA\Laravel\Traits\HasAuthorization;

describe('Authorization Error Handling', function (): void {
    it('tests authorization classes and traits exist', function (): void {
        // Test core classes exist
        expect(class_exists(OpenFgaManager::class))->toBeTrue();
        expect(class_exists(OpenFgaGate::class))->toBeTrue();
        expect(trait_exists(HasAuthorization::class))->toBeTrue();
        expect(trait_exists(FakesOpenFga::class))->toBeTrue();

        // Test that related classes and interfaces exist
        expect(interface_exists(AuthorizationObject::class))->toBeTrue();
        expect(interface_exists(AuthorizationType::class))->toBeTrue();
        expect(interface_exists(AuthorizationUser::class))->toBeTrue();
        expect(interface_exists(AuthorizableUser::class))->toBeTrue();
        expect(interface_exists(AuthorizationUserId::class))->toBeTrue();
        expect(interface_exists(ManagerInterface::class))->toBeTrue();
    });
});
