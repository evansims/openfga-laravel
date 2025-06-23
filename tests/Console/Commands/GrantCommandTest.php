<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Console\Commands;

use OpenFGA\Laravel\Console\Commands\GrantCommand;
use OpenFGA\Laravel\Tests\TestCase;

class GrantCommandTest extends TestCase
{
    public function test_grant_command_can_be_instantiated(): void
    {
        $command = new GrantCommand();
        
        $this->assertEquals('openfga:grant', $command->getName());
        $this->assertStringContainsString('Grant a permission to a user', $command->getDescription());
    }

    public function test_grant_command_signature(): void
    {
        $command = new GrantCommand();
        
        $definition = $command->getDefinition();
        
        // Check arguments
        $this->assertTrue($definition->hasArgument('user'));
        $this->assertTrue($definition->hasArgument('relation'));
        $this->assertTrue($definition->hasArgument('object'));
        
        // Check options
        $this->assertTrue($definition->hasOption('connection'));
        $this->assertTrue($definition->hasOption('json'));
        $this->assertTrue($definition->hasOption('batch'));
    }
}