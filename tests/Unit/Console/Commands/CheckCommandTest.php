<?php

declare(strict_types=1);

use OpenFGA\Laravel\Console\Commands\CheckCommand;
use OpenFGA\Laravel\Testing\FakesOpenFga;

describe('CheckCommand', function (): void {
    uses(FakesOpenFga::class);

    it('can be instantiated', function (): void {
        $command = new CheckCommand;

        expect($command->getName())->toBe('openfga:check');
        expect($command->getDescription())->toContain('Check if a user has a specific permission');
    });

    it('has correct signature', function (): void {
        $command = new CheckCommand;

        $definition = $command->getDefinition();

        // Check arguments
        expect($definition->hasArgument('user'))->toBeTrue();
        expect($definition->hasArgument('relation'))->toBeTrue();
        expect($definition->hasArgument('object'))->toBeTrue();

        // Check options
        expect($definition->hasOption('connection'))->toBeTrue();
        expect($definition->hasOption('json'))->toBeTrue();
        expect($definition->hasOption('contextual-tuple'))->toBeTrue();
        expect($definition->hasOption('context'))->toBeTrue();
    });
});
