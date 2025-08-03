<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Override;
use Symfony\Component\Console\Input\InputOption;

use function is_string;

/**
 * Command to generate a new permission migration.
 */
final class MakePermissionMigrationCommand extends GeneratorCommand
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new permission migration';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:permission-migration';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Permission migration';

    /**
     * Build the class with the given name.
     *
     * @param  string $name
     * @return string
     */
    #[Override]
    protected function buildClass($name)
    {
        $stub = parent::buildClass($name);

        // Add timestamp to migration name
        // Placeholder for datetime generation if needed
        $className = class_basename($name);

        return str_replace(
            ['{{ class }}', '{{ table }}'],
            [$className, $this->getTableName()],
            $stub,
        );
    }

    /**
     * Get the default namespace for the class.
     *
     * @param  string $rootNamespace
     * @return string
     */
    #[Override]
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace . '\Database\Migrations';
    }

    /**
     * Get the desired class name from the input.
     *
     * @return string
     */
    #[Override]
    protected function getNameInput()
    {
        $name = $this->argument('name');

        return trim(is_string($name) ? $name : '');
    }

    /**
     * Get the console command options.
     *
     * @return array<int, array<int, mixed>>
     */
    #[Override]
    protected function getOptions()
    {
        return [
            ['table', 't', InputOption::VALUE_OPTIONAL, 'The table to migrate permissions for'],
        ];
    }

    /**
     * Get the destination class path.
     *
     * @param  string $name
     * @return string
     */
    #[Override]
    protected function getPath($name)
    {
        $name = Str::replaceFirst($this->rootNamespace(), '', $name);
        $timestamp = date('Y_m_d_His');
        $className = str_replace('\\', '', $name);

        $databasePath = $this->laravel->databasePath();

        return $databasePath . '/migrations/' . $timestamp . '_' . Str::snake($className) . '.php';
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    #[Override]
    protected function getStub()
    {
        return __DIR__ . '/stubs/permission-migration.stub';
    }

    /**
     * Get the table name from the command options.
     */
    private function getTableName(): string
    {
        $table = $this->option('table');

        return is_string($table) ? $table : 'permissions';
    }
}
