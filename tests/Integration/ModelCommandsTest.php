<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Integration;

use Illuminate\Support\Facades\File;
use OpenFGA\Laravel\Tests\Support\FeatureTestCase;

final class ModelCommandsTest extends FeatureTestCase
{
    protected string $modelsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modelsPath = storage_path('openfga/models');

        // Ensure directory exists
        if (! is_dir($this->modelsPath)) {
            mkdir($this->modelsPath, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up created files
        if (is_dir($this->modelsPath)) {
            File::deleteDirectory($this->modelsPath);
        }

        parent::tearDown();
    }

    public function test_model_create_command_fails_without_force_when_file_exists(): void
    {
        $filename = $this->modelsPath . '/existing_model.fga';
        file_put_contents($filename, 'existing content');

        $this->artisan('openfga:model:create', ['name' => 'existing'])
            ->expectsOutput("Model file already exists: {$filename}")
            ->assertFailed();
    }

    public function test_model_create_command_interactive(): void
    {
        $this->artisan('openfga:model:create', ['name' => 'InteractiveModel'])
            ->expectsQuestion('Enter type name (or press enter to finish)', 'user')
            ->expectsQuestion('Enter relation name (or press enter to finish)', '')
            ->expectsQuestion('Enter type name (or press enter to finish)', '')
            ->expectsConfirmation('Do you want to create this model in OpenFGA now?', 'no')
            ->assertSuccessful();

        $content = file_get_contents($this->modelsPath . '/interactive_model_model.fga');
        $this->assertStringContainsString('type user', $content);
    }

    public function test_model_create_command_overwrites_with_force(): void
    {
        $filename = $this->modelsPath . '/force_model_model.fga';
        file_put_contents($filename, 'old content');

        $this->artisan('openfga:model:create', [
            'name' => 'ForceModel',
            '--template' => 'basic',
            '--force' => true,
        ])
            ->expectsConfirmation('Do you want to create this model in OpenFGA now?', 'no')
            ->assertSuccessful();

        $content = file_get_contents($filename);
        $this->assertStringNotContainsString('old content', $content);
        $this->assertStringContainsString('type document', $content);
    }

    public function test_model_create_command_with_file(): void
    {
        // Create a temporary DSL file
        $tempFile = tempnam(sys_get_temp_dir(), 'openfga_test');
        file_put_contents($tempFile, "model\n  schema 1.1\n\ntype test_user");

        $this->artisan('openfga:model:create', [
            'name' => 'FileModel',
            '--file' => $tempFile,
        ])
            ->expectsConfirmation('Do you want to create this model in OpenFGA now?', 'no')
            ->assertSuccessful();

        $content = file_get_contents($this->modelsPath . '/file_model_model.fga');
        $this->assertStringContainsString('type test_user', $content);

        unlink($tempFile);
    }

    public function test_model_create_command_with_organization_template(): void
    {
        $this->artisan('openfga:model:create', [
            'name' => 'OrgModel',
            '--template' => 'organization',
        ])
            ->expectsConfirmation('Do you want to create this model in OpenFGA now?', 'no')
            ->assertSuccessful();

        $content = file_get_contents($this->modelsPath . '/org_model_model.fga');
        $this->assertStringContainsString('type organization', $content);
        $this->assertStringContainsString('type department', $content);
        $this->assertStringContainsString('type project', $content);
    }

    public function test_model_create_command_with_template(): void
    {
        $this->artisan('openfga:model:create', [
            'name' => 'TestModel',
            '--template' => 'basic',
        ])
            ->expectsConfirmation('Do you want to create this model in OpenFGA now?', 'no')
            ->expectsOutput('Model DSL saved to: ' . $this->modelsPath . '/test_model_model.fga')
            ->assertSuccessful();

        $this->assertFileExists($this->modelsPath . '/test_model_model.fga');

        $content = file_get_contents($this->modelsPath . '/test_model_model.fga');
        $this->assertStringContainsString('model', $content);
        $this->assertStringContainsString('schema 1.1', $content);
        $this->assertStringContainsString('type user', $content);
        $this->assertStringContainsString('type document', $content);
    }

    public function test_model_validate_command_with_invalid_dsl(): void
    {
        $invalidDsl = <<<'DSL'
              schema 1.1

            type user

            type document
              relations
                define owner: [unknown_type]
            DSL;

        $tempFile = tempnam(sys_get_temp_dir(), 'invalid_dsl');
        file_put_contents($tempFile, $invalidDsl);

        $this->artisan('openfga:model:validate', ['--file' => $tempFile])
            ->assertFailed();

        unlink($tempFile);
    }

    public function test_model_validate_command_with_json_output(): void
    {
        $validDsl = "model\n  schema 1.1\n\ntype user";
        $tempFile = tempnam(sys_get_temp_dir(), 'json_dsl');
        file_put_contents($tempFile, $validDsl);

        $this->artisan('openfga:model:validate', [
            '--file' => $tempFile,
            '--json' => true,
        ])
            ->expectsOutputToContain('"valid": true')
            ->assertSuccessful();

        unlink($tempFile);
    }

    public function test_model_validate_command_with_nonexistent_file(): void
    {
        $this->artisan('openfga:model:validate', ['--file' => '/nonexistent/file.fga'])
            ->expectsOutput('File not found: /nonexistent/file.fga')
            ->assertFailed();
    }

    public function test_model_validate_command_with_valid_dsl(): void
    {
        $validDsl = <<<'DSL'
            model
              schema 1.1

            type user

            type document
              relations
                define owner: [user]
                define viewer: [user] or owner
            DSL;

        $tempFile = tempnam(sys_get_temp_dir(), 'valid_dsl');
        file_put_contents($tempFile, $validDsl);

        $this->artisan('openfga:model:validate', ['--file' => $tempFile])
            ->expectsOutput('✅ Model validation passed!')
            ->assertSuccessful();

        unlink($tempFile);
    }

    public function test_model_validate_command_without_file(): void
    {
        $this->artisan('openfga:model:validate')
            ->expectsOutput('Please specify a file to validate using --file option')
            ->assertFailed();
    }
}
