<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use OpenFGA\Laravel\OpenFgaManager;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ModelCreateCommand extends Command
{
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
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new OpenFGA authorization model from DSL';

    /**
     * Predefined model templates
     */
    private const TEMPLATES = [
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
     * Execute the console command.
     */
    public function handle(OpenFgaManager $manager): int
    {
        $name = $this->argument('name');
        $connection = $this->option('connection');
        $filePath = $this->option('file');
        $template = $this->option('template');

        // Generate filename for the model
        $filename = $this->generateFilename($name);

        // Check if file already exists
        if (! $this->option('force') && file_exists($filename)) {
            $this->error("Model file already exists: {$filename}");
            $this->info('Use --force to overwrite the existing file.');
            return self::FAILURE;
        }

        // Get the DSL content
        $dsl = $this->getDslContent($filePath, $template);

        if (! $dsl) {
            return self::FAILURE;
        }

        // Save the DSL file
        try {
            $this->saveDslFile($filename, $dsl);
            $this->info("Model DSL saved to: {$filename}");
        } catch (RuntimeException $e) {
            $this->error("Failed to save model file: {$e->getMessage()}");
            return self::FAILURE;
        }

        // Optionally create the model in OpenFGA
        if ($this->confirm('Do you want to create this model in OpenFGA now?')) {
            return $this->createModelInOpenFga($manager, $connection, $dsl, $name);
        }

        $this->info('You can create the model later using:');
        $this->comment("php artisan openfga:model:validate --file={$filename} --create");

        return self::SUCCESS;
    }

    /**
     * Generate filename for the model
     */
    private function generateFilename(string $name): string
    {
        $directory = storage_path('openfga/models');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = Str::snake($name) . '_model.fga';

        return $directory . '/' . $filename;
    }

    /**
     * Get DSL content from file or template
     */
    private function getDslContent(?string $filePath, ?string $template): ?string
    {
        // If a file is specified, read from it
        if ($filePath) {
            if (! file_exists($filePath)) {
                $this->error("File not found: {$filePath}");
                return null;
            }

            return file_get_contents($filePath);
        }

        // If a template is specified, use it
        if ($template) {
            if (! isset(self::TEMPLATES[$template])) {
                $this->error("Unknown template: {$template}");
                $this->info('Available templates: ' . implode(', ', array_keys(self::TEMPLATES)));
                return null;
            }

            return self::TEMPLATES[$template];
        }

        // Interactive model builder
        return $this->buildModelInteractively();
    }

    /**
     * Build a model interactively
     */
    private function buildModelInteractively(): string
    {
        $this->info('Building model interactively...');

        $types = [];

        while (true) {
            $typeName = $this->ask('Enter type name (or press enter to finish)');

            if (empty($typeName)) {
                break;
            }

            $relations = [];

            $this->info("Defining relations for type '{$typeName}'");

            while (true) {
                $relationName = $this->ask('Enter relation name (or press enter to finish)');

                if (empty($relationName)) {
                    break;
                }

                $allowedTypes = $this->ask("Allowed types for '{$relationName}' (comma-separated, e.g., user, group#member)", 'user');
                $allowedTypesArray = array_map('trim', explode(',', $allowedTypes));

                $relations[$relationName] = [
                    'allowed' => $allowedTypesArray,
                    'inherited' => [],
                ];

                if ($this->confirm("Does '{$relationName}' inherit from other relations?")) {
                    $inherited = $this->ask('Enter inherited relations (comma-separated)');
                    $relations[$relationName]['inherited'] = array_map('trim', explode(',', $inherited));
                }
            }

            $types[$typeName] = $relations;
        }

        return $this->generateDsl($types);
    }

    /**
     * Generate DSL from type definitions
     */
    private function generateDsl(array $types): string
    {
        $dsl = "model\n  schema 1.1\n\n";

        foreach ($types as $typeName => $relations) {
            $dsl .= "type {$typeName}\n";

            if (! empty($relations)) {
                $dsl .= "  relations\n";

                foreach ($relations as $relationName => $config) {
                    $dsl .= "    define {$relationName}: ";

                    $definition = '[' . implode(', ', $config['allowed']) . ']';

                    if (! empty($config['inherited'])) {
                        $definition .= ' or ' . implode(' or ', $config['inherited']);
                    }

                    $dsl .= $definition . "\n";
                }
            }

            $dsl .= "\n";
        }

        return rtrim($dsl);
    }

    /**
     * Save DSL to file
     */
    private function saveDslFile(string $filename, string $dsl): void
    {
        $result = file_put_contents($filename, $dsl);

        if ($result === false) {
            throw new RuntimeException('Failed to write file');
        }
    }

    /**
     * Create model in OpenFGA
     */
    private function createModelInOpenFga(OpenFgaManager $manager, ?string $connection, string $dsl, string $name): int
    {
        try {
            $client = $manager->connection($connection);

            $this->info('Creating model in OpenFGA...');

            // Here we would normally use the OpenFGA client to create the model
            // For now, we'll show a message since the actual implementation
            // depends on the OpenFGA PHP SDK methods

            $this->comment('Model DSL:');
            $this->line($dsl);

            $this->warn('Note: Actual model creation requires OpenFGA API integration.');
            $this->info('The model DSL has been saved and can be uploaded to OpenFGA using the dashboard or API.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create model: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Format the command output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return parent::execute($input, $output);
    }
}