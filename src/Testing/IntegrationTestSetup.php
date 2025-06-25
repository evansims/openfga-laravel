<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Illuminate\Console\Command;

/**
 * Helper class for setting up integration test environments
 */
class IntegrationTestSetup
{
    private Command $command;
    private string $openFgaUrl;
    
    public function __construct(Command $command, string $openFgaUrl = 'http://localhost:8080')
    {
        $this->command = $command;
        $this->openFgaUrl = $openFgaUrl;
    }

    /**
     * Check if OpenFGA is running
     */
    public function checkOpenFgaConnection(): bool
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                ],
            ]);
            
            $response = @file_get_contents($this->openFgaUrl . '/stores', false, $context);
            return $response !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Start OpenFGA using Docker
     */
    public function startOpenFgaDocker(): bool
    {
        $this->command->info('Starting OpenFGA with Docker...');
        
        // Check if Docker is available
        exec('docker --version 2>&1', $output, $returnCode);
        if ($returnCode !== 0) {
            $this->command->error('Docker is not installed or not in PATH');
            return false;
        }

        // Check if OpenFGA container is already running
        exec('docker ps --filter name=openfga-test --format "{{.Names}}"', $output, $returnCode);
        if (!empty($output) && in_array('openfga-test', $output)) {
            $this->command->info('OpenFGA container is already running');
            return true;
        }

        // Start OpenFGA container
        $dockerCommand = sprintf(
            'docker run -d --name openfga-test -p %s:8080 -p 8081:8081 -p 3000:3000 openfga/openfga:latest run',
            parse_url($this->openFgaUrl, PHP_URL_PORT) ?: '8080'
        );

        exec($dockerCommand . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            $this->command->error('Failed to start OpenFGA container');
            $this->command->error(implode("\n", $output));
            return false;
        }

        $this->command->info('OpenFGA container started successfully');
        
        // Wait for OpenFGA to be ready
        $this->command->info('Waiting for OpenFGA to be ready...');
        $attempts = 0;
        $maxAttempts = 30;
        
        while ($attempts < $maxAttempts) {
            if ($this->checkOpenFgaConnection()) {
                $this->command->info('OpenFGA is ready!');
                return true;
            }
            
            sleep(1);
            $attempts++;
            $this->command->line("Waiting... ({$attempts}/{$maxAttempts})");
        }

        $this->command->error('OpenFGA failed to start within timeout');
        return false;
    }

    /**
     * Stop OpenFGA Docker container
     */
    public function stopOpenFgaDocker(): void
    {
        $this->command->info('Stopping OpenFGA container...');
        exec('docker stop openfga-test 2>&1');
        exec('docker rm openfga-test 2>&1');
        $this->command->info('OpenFGA container stopped');
    }

    /**
     * Create example .env.testing file
     */
    public function createTestEnvFile(): void
    {
        $envContent = <<<'ENV'
# OpenFGA Integration Test Configuration
OPENFGA_RUN_INTEGRATION_TESTS=true
OPENFGA_TEST_URL=http://localhost:8080
OPENFGA_TEST_AUTH_METHOD=none

# Optional: Use specific test database
DB_CONNECTION=sqlite
DB_DATABASE=:memory:

# Optional: Disable unnecessary services for tests
BROADCAST_DRIVER=log
CACHE_DRIVER=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
MAIL_MAILER=array
ENV;

        $envPath = base_path('.env.testing');
        
        if (file_exists($envPath)) {
            if (!$this->command->confirm('.env.testing already exists. Overwrite?')) {
                return;
            }
        }

        file_put_contents($envPath, $envContent);
        $this->command->info('Created .env.testing file');
    }

    /**
     * Create PHPUnit configuration for integration tests
     */
    public function createPhpUnitConfig(): void
    {
        $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="./vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">./tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory suffix="Test.php">./tests/Feature</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory suffix="Test.php">./tests/Integration</directory>
        </testsuite>
    </testsuites>
    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">./src</directory>
        </include>
    </coverage>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_DRIVER" value="array"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
        
        <!-- OpenFGA Integration Test Settings -->
        <env name="OPENFGA_RUN_INTEGRATION_TESTS" value="false"/>
        <env name="OPENFGA_TEST_URL" value="http://localhost:8080"/>
    </php>
</phpunit>
XML;

        $xmlPath = base_path('phpunit.xml');
        
        if (file_exists($xmlPath)) {
            if (!$this->command->confirm('phpunit.xml already exists. Overwrite?')) {
                return;
            }
        }

        file_put_contents($xmlPath, $xmlContent);
        $this->command->info('Created phpunit.xml with integration test configuration');
    }

    /**
     * Create GitHub Actions workflow for integration tests
     */
    public function createGitHubActionsWorkflow(): void
    {
        $workflowContent = <<<'YAML'
name: Integration Tests

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main, develop ]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      openfga:
        image: openfga/openfga:latest
        ports:
          - 8080:8080
        options: >-
          --health-cmd "wget -q -O - http://localhost:8080/stores || exit 1"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10

    steps:
    - uses: actions/checkout@v3
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.3'
        extensions: mbstring, xml, ctype, iconv, intl, pdo_sqlite
        coverage: xdebug

    - name: Get Composer Cache Directory
      id: composer-cache
      run: |
        echo "dir=$(composer config cache-files-dir)" >> $GITHUB_OUTPUT

    - uses: actions/cache@v3
      with:
        path: ${{ steps.composer-cache.outputs.dir }}
        key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
        restore-keys: |
          ${{ runner.os }}-composer-

    - name: Install Dependencies
      run: composer install --prefer-dist --no-progress

    - name: Run Integration Tests
      env:
        OPENFGA_RUN_INTEGRATION_TESTS: true
        OPENFGA_TEST_URL: http://localhost:8080
      run: vendor/bin/phpunit --testsuite Integration

    - name: Upload Coverage
      uses: codecov/codecov-action@v3
      if: success()
      with:
        file: ./coverage.xml
YAML;

        $workflowDir = base_path('.github/workflows');
        
        if (!is_dir($workflowDir)) {
            mkdir($workflowDir, 0755, true);
        }

        $workflowPath = $workflowDir . '/integration-tests.yml';
        
        if (file_exists($workflowPath)) {
            if (!$this->command->confirm('integration-tests.yml already exists. Overwrite?')) {
                return;
            }
        }

        file_put_contents($workflowPath, $workflowContent);
        $this->command->info('Created GitHub Actions workflow for integration tests');
    }

    /**
     * Display setup instructions
     */
    public function displayInstructions(): void
    {
        $this->command->info('
Integration Test Setup Complete!

To run integration tests:

1. Start OpenFGA (if not using Docker):
   openfga run

2. Run integration tests:
   OPENFGA_RUN_INTEGRATION_TESTS=true ./vendor/bin/phpunit --testsuite Integration

3. Or run all tests including integration:
   OPENFGA_RUN_INTEGRATION_TESTS=true ./vendor/bin/phpunit

4. To run tests in CI/CD, use the provided GitHub Actions workflow.

For more information, see the integration testing documentation.
        ');
    }
}