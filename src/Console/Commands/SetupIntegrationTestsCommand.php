<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\Testing\IntegrationTestSetup;

/**
 * Command to set up integration testing environment.
 */
final class SetupIntegrationTestsCommand extends Command
{
    protected $description = 'Set up integration testing environment for OpenFGA Laravel';

    protected $signature = 'openfga:setup-integration-tests
                            {--docker : Start OpenFGA using Docker}
                            {--env : Create .env.testing file}
                            {--phpunit : Create PHPUnit configuration}
                            {--github : Create GitHub Actions workflow}
                            {--all : Set up everything}';

    public function handle(): int
    {
        $this->info('🚀 Setting up OpenFGA Laravel Integration Tests');
        $this->newLine();

        $setup = new IntegrationTestSetup($this);

        // Check if any option is provided, otherwise ask
        if (! $this->hasAnyOption()) {
            $this->interactiveSetup($setup);

            return 0;
        }

        // Process options
        if ($this->option('all')) {
            $this->setupEverything($setup);
        } else {
            $this->setupSelectedOptions($setup);
        }

        $setup->displayInstructions();

        return 0;
    }

    private function hasAnyOption(): bool
    {
        if ($this->option('docker')) {
            return true;
        }

        if ($this->option('env')) {
            return true;
        }

        if ($this->option('phpunit')) {
            return true;
        }

        if ($this->option('github')) {
            return true;
        }

        return (bool) $this->option('all');
    }

    private function interactiveSetup(IntegrationTestSetup $setup): void
    {
        $this->info('What would you like to set up?');

        if ($this->confirm('Check OpenFGA connection?')) {
            if ($setup->checkOpenFgaConnection()) {
                $this->info('✅ OpenFGA is running and accessible');
            } else {
                $this->warn('⚠️  OpenFGA is not accessible at ' . env('OPENFGA_TEST_URL', 'http://localhost:8080'));

                if ($this->confirm('Would you like to start OpenFGA with Docker?')) {
                    $setup->startOpenFgaDocker();
                }
            }
        }

        if ($this->confirm('Create .env.testing file?')) {
            $setup->createTestEnvFile();
        }

        if ($this->confirm('Create PHPUnit configuration?')) {
            $setup->createPhpUnitConfig();
        }

        if ($this->confirm('Create GitHub Actions workflow?')) {
            $setup->createGitHubActionsWorkflow();
        }
    }

    private function setupEverything(IntegrationTestSetup $setup): void
    {
        $this->info('Setting up everything...');

        // Check OpenFGA
        if (! $setup->checkOpenFgaConnection()) {
            $this->warn('OpenFGA is not running');
            $setup->startOpenFgaDocker();
        } else {
            $this->info('✅ OpenFGA is already running');
        }

        // Create all configuration files
        $setup->createTestEnvFile();
        $setup->createPhpUnitConfig();
        $setup->createGitHubActionsWorkflow();
    }

    private function setupSelectedOptions(IntegrationTestSetup $setup): void
    {
        if ($this->option('docker')) {
            if (! $setup->checkOpenFgaConnection()) {
                $setup->startOpenFgaDocker();
            } else {
                $this->info('OpenFGA is already running');
            }
        }

        if ($this->option('env')) {
            $setup->createTestEnvFile();
        }

        if ($this->option('phpunit')) {
            $setup->createPhpUnitConfig();
        }

        if ($this->option('github')) {
            $setup->createGitHubActionsWorkflow();
        }
    }
}
