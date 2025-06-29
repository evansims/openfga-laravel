<?php

declare(strict_types=1);

use OpenFGA\Laravel\Authorization\OpenFgaGate;
use OpenFGA\Laravel\Contracts\{AuthorizableUser, AuthorizationObject, AuthorizationType, AuthorizationUser, AuthorizationUserId, ManagerInterface};
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Testing\FakesOpenFga;
use OpenFGA\Laravel\Tests\Support\TestDebugging;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\Traits\HasAuthorization;

uses(TestCase::class);

describe('Authorization Error Handling', function (): void {
    it('ensures all required authorization components are available', function (): void {
        // Core classes must exist for the authorization system to function
        TestDebugging::assertWithContext(
            class_exists(OpenFgaManager::class),
            'OpenFgaManager class is required for managing OpenFGA connections',
            ['Expected class' => OpenFgaManager::class],
        );

        TestDebugging::assertWithContext(
            class_exists(OpenFgaGate::class),
            'OpenFgaGate class is required for Laravel Gate integration',
            ['Expected class' => OpenFgaGate::class],
        );

        // Essential traits for model authorization
        TestDebugging::assertWithContext(
            trait_exists(HasAuthorization::class),
            'HasAuthorization trait is required for adding authorization methods to models',
            ['Expected trait' => HasAuthorization::class],
        );

        TestDebugging::assertWithContext(
            trait_exists(FakesOpenFga::class),
            'FakesOpenFga trait is required for testing authorization logic',
            ['Expected trait' => FakesOpenFga::class],
        );

        // Authorization contracts that define the system's behavior
        $requiredInterfaces = [
            AuthorizationObject::class => 'Defines objects that can be authorized',
            AuthorizationType::class => 'Defines authorization type identifiers',
            AuthorizationUser::class => 'Defines users in the authorization system',
            AuthorizableUser::class => 'Combines user and ID contracts',
            AuthorizationUserId::class => 'Defines user ID format for authorization',
            ManagerInterface::class => 'Defines the OpenFGA manager contract',
        ];

        foreach ($requiredInterfaces as $interface => $purpose) {
            TestDebugging::assertWithContext(
                interface_exists($interface),
                "Interface {$interface} is required: {$purpose}",
                [
                    'Interface' => $interface,
                    'Purpose' => $purpose,
                    'Type' => 'Authorization Contract',
                ],
            );
        }

        TestDebugging::log('All authorization components verified', [
            'classes' => 2,
            'traits' => 2,
            'interfaces' => count($requiredInterfaces),
        ]);

        // Add an expectation to satisfy PHPUnit
        expect(true)->toBeTrue();
    });
});
