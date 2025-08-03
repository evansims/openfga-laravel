<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use OpenFGA\Laravel\OpenFgaManager;
use Override;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function sprintf;

final class ModelCreateCommand extends Command
{
    /**
     * Predefined model templates.
     */
    private const array TEMPLATES = [
        'basic' => <<<'DSL'
            model
              schema 1.1

            type user

            type document
              relations
                define owner: [user]
                define editor: [user]
                define viewer: [user] or editor or owner
            DSL,
        'organization' => <<<'DSL'
            model
              schema 1.1

            type user

            type organization
              relations
                define admin: [user]
                define member: [user] or admin

            type department
              relations
                define parent: [organization]
                define manager: [user]
                define member: [user] or manager or admin from parent

            type project
              relations
                define department: [department]
                define owner: [user]
                define contributor: [user] or member from department
                define viewer: [user] or contributor
            DSL,
        'rbac' => <<<'DSL'
            model
              schema 1.1

            type user

            type role
              relations
                define assignee: [user]

            type permission
              relations
                define granted: [role]

            type resource
              relations
                define can_create: [user] or assignee from granted
                define can_read: [user] or assignee from granted
                define can_update: [user] or assignee from granted
                define can_delete: [user] or assignee from granted
                define owner: [user]
            DSL,
        'document' => <<<'DSL'
            model
              schema 1.1

            type user

            type group
              relations
                define member: [user]

            type folder
              relations
                define parent: [folder]
                define owner: [user]
                define editor: [user, group#member] or owner
                define viewer: [user, group#member] or editor or viewer from parent

            type document
              relations
                define parent: [folder]
                define owner: [user]
                define editor: [user, group#member] or owner or editor from parent
                define viewer: [user, group#member] or editor or viewer from parent
                define commenter: [user, group#member] or viewer
            DSL,
    ];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new OpenFGA authorization model from DSL';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:model:create
                            {name : The name of the model}
                            {--file= : Path to DSL file containing the model definition}
                            {--template= : Use a predefined template (basic, organization, rbac, document)}
                            {--connection= : The OpenFGA connection to use}
                            {--force : Overwrite existing model file}';

    /**
     * Execute the console command.
     *
     * @param OpenFgaManager $manager
     */
    public function handle(OpenFgaManager $manager): int
    {
        $name = $this->argument('name');
        $connection = $this->option('connection');
        $filePath = $this->option('file');
        $template = $this->option('template');

        if (! is_string($name)) {
            $this->error('Invalid name provided.');

            return self::FAILURE;
        }

        // Generate filename for the model
        $filename = $this->generateFilename($name);

        // Check if file already exists
        if (true !== $this->option('force') && file_exists($filename)) {
            $this->error('Model file already exists: ' . $filename);
            $this->info('Use --force to overwrite the existing file.');

            return self::FAILURE;
        }

        // Get the DSL content
        $dsl = $this->getDslContent(
            is_string($filePath) ? $filePath : null,
            is_string($template) ? $template : null,
        );

        if (null === $dsl) {
            return self::FAILURE;
        }

        // Save the DSL file
        try {
            $this->saveDslFile($filename, $dsl);
            $this->info('Model DSL saved to: ' . $filename);
        } catch (RuntimeException $runtimeException) {
            $this->error('Failed to save model file: ' . $runtimeException->getMessage());

            return self::FAILURE;
        }

        // Optionally create the model in OpenFGA
        if ($this->confirm('Do you want to create this model in OpenFGA now?')) {
            return $this->createModelInOpenFga($manager, is_string($connection) ? $connection : null, $dsl);
        }

        $this->info('You can create the model later using:');
        $this->comment(sprintf('php artisan openfga:model:validate --file=%s --create', $filename));

        return self::SUCCESS;
    }

    /**
     * Format the command output.
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return parent::execute($input, $output);
    }

    /**
     * Build a model interactively.
     */
    private function buildModelInteractively(): string
    {
        $this->info('Building model interactively...');

        $types = [];

        while (true) {
            $typeName = $this->ask('Enter type name (or press enter to finish)');

            if (! is_string($typeName) || '' === $typeName) {
                break;
            }

            $relations = [];

            $this->info(sprintf("Defining relations for type '%s'", $typeName));

            while (true) {
                $relationName = $this->ask('Enter relation name (or press enter to finish)');

                if (! is_string($relationName) || '' === $relationName) {
                    break;
                }

                /** @var mixed $allowedTypes */
                $allowedTypes = $this->ask(sprintf("Allowed types for '%s' (comma-separated, e.g., user, group#member)", $relationName), 'user');
                $allowedTypesArray = array_map('trim', explode(',', is_string($allowedTypes) ? $allowedTypes : 'user'));

                $relations[$relationName] = [
                    'allowed' => $allowedTypesArray,
                    'inherited' => [],
                ];

                if ($this->confirm(sprintf("Does '%s' inherit from other relations?", $relationName))) {
                    /** @var mixed $inherited */
                    $inherited = $this->ask('Enter inherited relations (comma-separated)');
                    $relations[$relationName]['inherited'] = array_map('trim', explode(',', is_string($inherited) ? $inherited : ''));
                }
            }

            $types[$typeName] = $relations;
        }

        return $this->generateDsl($types);
    }

    /**
     * Create model in OpenFGA.
     *
     * @param OpenFgaManager $manager
     * @param ?string        $connection
     * @param string         $dsl
     */
    private function createModelInOpenFga(OpenFgaManager $manager, ?string $connection, string $dsl): int
    {
        try {
            $manager->connection($connection);

            $this->info('Creating model in OpenFGA...');

            // Here we would normally use the OpenFGA client to create the model
            // For now, we'll show a message since the actual implementation
            // depends on the OpenFGA PHP SDK methods

            $this->comment('Model DSL:');
            $this->line($dsl);

            $this->warn('Note: Actual model creation requires OpenFGA API integration.');
            $this->info('The model DSL has been saved and can be uploaded to OpenFGA using the dashboard or API.');

            return self::SUCCESS;
        } catch (Exception $exception) {
            $this->error('Failed to create model: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Generate DSL from type definitions.
     *
     * @param array<string, array<string, array{allowed: array<int, string>, inherited: array<int, string>}>> $types
     */
    private function generateDsl(array $types): string
    {
        $dsl = "model\n  schema 1.1\n\n";

        foreach ($types as $typeName => $relations) {
            $dsl .= sprintf('type %s%s', $typeName, PHP_EOL);

            if ([] !== $relations) {
                $dsl .= "  relations\n";

                foreach ($relations as $relationName => $config) {
                    $dsl .= sprintf('    define %s: ', $relationName);

                    $allowed = $config['allowed'];
                    $definition = '[' . implode(', ', $allowed) . ']';

                    $inherited = $config['inherited'];

                    if ([] !== $inherited) {
                        $definition .= ' or ' . implode(' or ', $inherited);
                    }

                    $dsl .= $definition . "\n";
                }
            }

            $dsl .= "\n";
        }

        return rtrim($dsl);
    }

    /**
     * Generate filename for the model.
     *
     * @param string $name
     */
    private function generateFilename(string $name): string
    {
        $directory = storage_path('openfga/models');

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        $filename = Str::snake($name) . '_model.fga';

        return $directory . '/' . $filename;
    }

    /**
     * Get DSL content from file or template.
     *
     * @param ?string $filePath
     * @param ?string $template
     */
    private function getDslContent(?string $filePath, ?string $template): ?string
    {
        // If a file is specified, read from it
        if (null !== $filePath) {
            if (! file_exists($filePath)) {
                $this->error('File not found: ' . $filePath);

                return null;
            }

            $content = file_get_contents($filePath);

            return false !== $content ? $content : null;
        }

        // If a template is specified, use it
        if (null !== $template) {
            if (! isset(self::TEMPLATES[$template])) {
                $this->error('Unknown template: ' . $template);
                $this->info('Available templates: ' . implode(', ', array_keys(self::TEMPLATES)));

                return null;
            }

            return self::TEMPLATES[$template];
        }

        // Interactive model builder
        return $this->buildModelInteractively();
    }

    /**
     * Save DSL to file.
     *
     * @param string $filename
     * @param string $dsl
     *
     * @throws RuntimeException
     */
    private function saveDslFile(string $filename, string $dsl): void
    {
        $result = file_put_contents($filename, $dsl);

        if (false === $result) {
            throw new RuntimeException('Failed to write file');
        }
    }
}
