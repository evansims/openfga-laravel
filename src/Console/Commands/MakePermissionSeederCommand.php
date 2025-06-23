<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\GeneratorCommand;

/**
 * Command to generate a new permission seeder.
 */
class MakePermissionSeederCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:permission-seeder';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new permission seeder';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Permission seeder';

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return __DIR__ . '/stubs/permission-seeder.stub';
    }

    /**
     * Get the default namespace for the class.
     *
     * @param string $rootNamespace
     *
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace . '\Database\Seeders';
    }
}