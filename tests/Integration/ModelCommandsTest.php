<?php

declare(strict_types=1);
use OpenFGA\Laravel\Tests\Support\FeatureTestCase;

uses(FeatureTestCase::class);

use Illuminate\Support\Facades\File;

describe('Model Commands', function (): void {
    beforeEach(function (): void {
        $this->modelsPath = storage_path('openfga/models');

        // Ensure directory exists
        if (! is_dir($this->modelsPath)) {
            mkdir(directory: $this->modelsPath, permissions: 0o755, recursive: true);
        }
    });

    afterEach(function (): void {
        // Clean up created files
        if (is_dir($this->modelsPath)) {
            File::deleteDirectory($this->modelsPath);
        }
    });

    it('model create command fails without force when file exists', function (): void {
        $filename = $this->modelsPath . '/existing_model.fga';
        file_put_contents($filename, 'existing content');

        $this->artisan('openfga:model:create', ['name' => 'existing'])
            ->expectsOutput('Model file already exists: ' . $filename)
            ->assertFailed();
    });

    it('model create command interactive', function (): void {
        $this->artisan('openfga:model:create', ['name' => 'InteractiveModel'])
            ->expectsQuestion('Enter type name (or press enter to finish)', 'user')
            ->expectsQuestion('Enter relation name (or press enter to finish)', '')
            ->expectsQuestion('Enter type name (or press enter to finish)', '')
            ->expectsConfirmation('Do you want to create this model in OpenFGA now?', 'no')
            ->assertSuccessful();

        $content = file_get_contents($this->modelsPath . '/interactive_model_model.fga');
        expect($content)->toContain('type user');
    });

    it('model create command overwrites with force', function (): void {
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
        expect($content)->not->toContain('old content');
        expect($content)->toContain('type document');
    });

    it('model create command with file', function (): void {
        // Create a temporary DSL file
        $tempFile = tempnam(directory: sys_get_temp_dir(), prefix: 'openfga_test');
        file_put_contents($tempFile, "model\n  schema 1.1\n\ntype test_user");

        $this->artisan('openfga:model:create', [
            'name' => 'FileModel',
            '--file' => $tempFile,
        ])
            ->expectsConfirmation('Do you want to create this model in OpenFGA now?', 'no')
            ->assertSuccessful();

        $content = file_get_contents($this->modelsPath . '/file_model_model.fga');
        expect($content)->toContain('type test_user');

        unlink(filename: $tempFile);
    });

    it('model create command with organization template', function (): void {
        $this->artisan('openfga:model:create', [
            'name' => 'OrgModel',
            '--template' => 'organization',
        ])
            ->expectsConfirmation('Do you want to create this model in OpenFGA now?', 'no')
            ->assertSuccessful();

        $content = file_get_contents($this->modelsPath . '/org_model_model.fga');
        expect($content)->toContain('type organization');
        expect($content)->toContain('type department');
        expect($content)->toContain('type project');
    });

    it('model create command with template', function (): void {
        $this->artisan('openfga:model:create', [
            'name' => 'TestModel',
            '--template' => 'basic',
        ])
            ->expectsConfirmation('Do you want to create this model in OpenFGA now?', 'no')
            ->expectsOutput('Model DSL saved to: ' . $this->modelsPath . '/test_model_model.fga')
            ->assertSuccessful();

        expect($this->modelsPath . '/test_model_model.fga')->toBeFile();

        $content = file_get_contents($this->modelsPath . '/test_model_model.fga');
        expect($content)->toContain('model');
        expect($content)->toContain('schema 1.1');
        expect($content)->toContain('type user');
        expect($content)->toContain('type document');
    });

    it('model validate command with invalid dsl', function (): void {
        $invalidDsl = <<<'DSL'
              schema 1.1

            type user

            type document
              relations
                define owner: [unknown_type]
            DSL;

        $tempFile = tempnam(directory: sys_get_temp_dir(), prefix: 'invalid_dsl');
        file_put_contents($tempFile, $invalidDsl);

        $this->artisan('openfga:model:validate', ['--file' => $tempFile])
            ->assertFailed();

        unlink(filename: $tempFile);
    });

    it('model validate command with json output', function (): void {
        $validDsl = "model\n  schema 1.1\n\ntype user";
        $tempFile = tempnam(directory: sys_get_temp_dir(), prefix: 'json_dsl');
        file_put_contents($tempFile, $validDsl);

        $this->artisan('openfga:model:validate', [
            '--file' => $tempFile,
            '--json' => true,
        ])
            ->expectsOutputToContain('"valid": true')
            ->assertSuccessful();

        unlink(filename: $tempFile);
    });

    it('model validate command with nonexistent file', function (): void {
        $this->artisan('openfga:model:validate', ['--file' => '/nonexistent/file.fga'])
            ->expectsOutput('File not found: /nonexistent/file.fga')
            ->assertFailed();
    });

    it('model validate command with valid dsl', function (): void {
        $validDsl = <<<'DSL'
            model
              schema 1.1

            type user

            type document
              relations
                define owner: [user]
                define viewer: [user] or owner
            DSL;

        $tempFile = tempnam(directory: sys_get_temp_dir(), prefix: 'valid_dsl');
        file_put_contents($tempFile, $validDsl);

        $this->artisan('openfga:model:validate', ['--file' => $tempFile])
            ->expectsOutput('✅ Model validation passed!')
            ->assertSuccessful();

        unlink(filename: $tempFile);
    });

    it('model validate command without file', function (): void {
        $this->artisan('openfga:model:validate')
            ->expectsOutput('Please specify a file to validate using --file option')
            ->assertFailed();
    });
});
