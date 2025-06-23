<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Console\Commands;

use OpenFGA\Laravel\Console\Commands\CheckCommand;
use OpenFGA\Laravel\Testing\FakesOpenFga;
use OpenFGA\Laravel\Tests\TestCase;

class CheckCommandTest extends TestCase
{
    use FakesOpenFga;

    public function test_check_command_can_be_instantiated(): void
    {
        $command = new CheckCommand();
        
        $this->assertEquals('openfga:check', $command->getName());
        $this->assertStringContainsString('Check if a user has a specific permission', $command->getDescription());
    }

    public function test_check_command_signature(): void
    {
        $command = new CheckCommand();
        
        $definition = $command->getDefinition();
        
        // Check arguments
        $this->assertTrue($definition->hasArgument('user'));
        $this->assertTrue($definition->hasArgument('relation'));
        $this->assertTrue($definition->hasArgument('object'));
        
        // Check options
        $this->assertTrue($definition->hasOption('connection'));
        $this->assertTrue($definition->hasOption('json'));
        $this->assertTrue($definition->hasOption('contextual-tuple'));
        $this->assertTrue($definition->hasOption('context'));
    }
}