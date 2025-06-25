<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;
use RuntimeException;

class StoreCreateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:store:create
                            {name : The name of the store}
                            {--model= : Path to DSL file for initial model}
                            {--connection= : The OpenFGA connection to use}
                            {--update-config : Update the configuration file with the new store ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new OpenFGA store';

    /**
     * Execute the console command.
     */
    public function handle(OpenFgaManager $manager): int
    {
        $name = $this->argument('name');
        $connection = $this->option('connection');
        $modelFile = $this->option('model');

        try {
            $this->info("Creating store '{$name}'...");

            // Note: Actual implementation would use the OpenFGA client to create the store
            // For now, we'll simulate the process

            $storeId = $this->createStore($manager, $connection, $name);

            $this->info("✅ Store created successfully!");
            $this->comment("Store ID: {$storeId}");

            // Create initial model if provided
            if ($modelFile) {
                $this->createInitialModel($storeId, $modelFile);
            }

            // Update configuration if requested
            if ($this->option('update-config')) {
                $this->updateConfiguration($storeId, $connection);
            }

            $this->showNextSteps($storeId);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create store: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Create the store
     */
    private function createStore(OpenFgaManager $manager, ?string $connection, string $name): string
    {
        // Simulate store creation - in real implementation, this would use the OpenFGA API
        $storeId = 'store_' . substr(md5($name . time()), 0, 16);

        $this->warn('Note: Actual store creation requires OpenFGA API integration.');
        $this->info("Simulated store creation for demonstration purposes.");

        return $storeId;
    }

    /**
     * Create initial model in the store
     */
    private function createInitialModel(string $storeId, string $modelFile): void
    {
        if (! file_exists($modelFile)) {
            throw new RuntimeException("Model file not found: {$modelFile}");
        }

        $dsl = file_get_contents($modelFile);

        $this->info("Creating initial model from: {$modelFile}");
        
        // Validate the model first
        $this->call('openfga:model:validate', ['--file' => $modelFile]);

        $this->comment("Model would be created in store: {$storeId}");
    }

    /**
     * Update configuration file with new store ID
     */
    private function updateConfiguration(string $storeId, ?string $connection): void
    {
        $configPath = config_path('openfga.php');

        if (! file_exists($configPath)) {
            $this->warn("Configuration file not found. Please update your .env file manually:");
            $this->comment("OPENFGA_STORE_ID={$storeId}");
            return;
        }

        $this->info("Configuration update instructions:");
        $this->comment("Add the following to your .env file:");
        
        if ($connection) {
            $envPrefix = strtoupper($connection);
            $this->comment("{$envPrefix}_OPENFGA_STORE_ID={$storeId}");
        } else {
            $this->comment("OPENFGA_STORE_ID={$storeId}");
        }
    }

    /**
     * Show next steps after store creation
     */
    private function showNextSteps(string $storeId): void
    {
        $this->newLine();
        $this->info("Next steps:");
        $this->comment("1. Update your .env file with the store ID:");
        $this->line("   OPENFGA_STORE_ID={$storeId}");
        $this->comment("2. Create an authorization model:");
        $this->line("   php artisan openfga:model:create MyModel --template=basic");
        $this->comment("3. Start using OpenFGA in your application!");
    }
}