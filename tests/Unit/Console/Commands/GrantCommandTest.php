<?php

declare(strict_types=1);

use OpenFGA\Laravel\Console\Commands\GrantCommand;

describe('GrantCommand', function (): void {
    it('can be instantiated', function (): void {
        $command = new GrantCommand;

        expect($command->getName())->toBe('openfga:grant');
        expect($command->getDescription())->toContain('Grant a permission to a user');
    });

    it('has correct signature', function (): void {
        $command = new GrantCommand;

        $definition = $command->getDefinition();

        // Check arguments
        expect($definition->hasArgument('user'))->toBeTrue();
        expect($definition->hasArgument('relation'))->toBeTrue();
        expect($definition->hasArgument('object'))->toBeTrue();

        // Check options
        expect($definition->hasOption('connection'))->toBeTrue();
        expect($definition->hasOption('json'))->toBeTrue();
        expect($definition->hasOption('batch'))->toBeTrue();
    });
});
