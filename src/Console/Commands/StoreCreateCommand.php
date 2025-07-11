<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;
use RuntimeException;

use function is_string;
use function sprintf;

final class StoreCreateCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Create a new OpenFGA store';

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
     * Execute the console command.
     *
     * @param OpenFgaManager $manager
     */
    public function handle(OpenFgaManager $manager): int
    {
        $name = $this->argument('name');
        $connection = $this->option('connection');
        $modelFile = $this->option('model');

        try {
            /** @var string $storeName */
            $storeName = $name;
            $this->info(sprintf("Creating store '%s'...", $storeName));

            // Note: Actual implementation would use the OpenFGA client to create the store
            // For now, we'll simulate the process

            $storeId = $this->createStore($storeName);

            $this->info('✅ Store created successfully!');
            $this->comment('Store ID: ' . $storeId);

            // Create initial model if provided
            if (is_string($modelFile)) {
                $this->createInitialModel($storeId, $modelFile);
            }

            // Update configuration if requested
            if (true === $this->option('update-config')) {
                $this->updateConfiguration($storeId, is_string($connection) ? $connection : null);
            }

            $this->showNextSteps($storeId);

            return self::SUCCESS;
        } catch (Exception $exception) {
            $this->error('Failed to create store: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Create initial model in the store.
     *
     * @param string $storeId
     * @param string $modelFile
     *
     * @throws RuntimeException
     */
    private function createInitialModel(string $storeId, string $modelFile): void
    {
        if (! file_exists($modelFile)) {
            throw new RuntimeException('Model file not found: ' . $modelFile);
        }

        file_get_contents($modelFile);

        $this->info('Creating initial model from: ' . $modelFile);

        // Validate the model first
        $this->call('openfga:model:validate', ['--file' => $modelFile]);

        $this->comment('Model would be created in store: ' . $storeId);
    }

    /**
     * Create the store.
     *
     * @param string $name
     */
    private function createStore(string $name): string
    {
        // Simulate store creation - in real implementation, this would use the OpenFGA API
        $storeId = 'store_' . substr(md5($name . time()), 0, 16);

        $this->warn('Note: Actual store creation requires OpenFGA API integration.');
        $this->info('Simulated store creation for demonstration purposes.');

        return $storeId;
    }

    /**
     * Show next steps after store creation.
     *
     * @param string $storeId
     */
    private function showNextSteps(string $storeId): void
    {
        $this->newLine();
        $this->info('Next steps:');
        $this->comment('1. Update your .env file with the store ID:');
        $this->line('   OPENFGA_STORE_ID=' . $storeId);
        $this->comment('2. Create an authorization model:');
        $this->line('   php artisan openfga:model:create MyModel --template=basic');
        $this->comment('3. Start using OpenFGA in your application!');
    }

    /**
     * Update configuration file with new store ID.
     *
     * @param string  $storeId
     * @param ?string $connection
     */
    private function updateConfiguration(string $storeId, ?string $connection): void
    {
        $configPath = config_path('openfga.php');

        if (! file_exists($configPath)) {
            $this->warn('Configuration file not found. Please update your .env file manually:');
            $this->comment('OPENFGA_STORE_ID=' . $storeId);

            return;
        }

        $this->info('Configuration update instructions:');
        $this->comment('Add the following to your .env file:');

        if (null !== $connection) {
            $envPrefix = strtoupper($connection);
            $this->comment(sprintf('%s_OPENFGA_STORE_ID=%s', $envPrefix, $storeId));
        } else {
            $this->comment('OPENFGA_STORE_ID=' . $storeId);
        }
    }
}
