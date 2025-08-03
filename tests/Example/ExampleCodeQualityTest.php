<?php

declare(strict_types=1);
describe('Example Application Code Quality', function (): void {
    $examplePath = __DIR__ . '/../../example';

    it('has proper PHP declarations in controllers', function () use ($examplePath): void {
        $controllers = glob($examplePath . '/app/Http/Controllers/**/*.php');

        foreach ($controllers as $controller) {
            $content = file_get_contents($controller);
            $filename = basename($controller);

            expect($content)
                ->toContain('<?php')
                ->toContain('declare(strict_types=1);')
                ->toContain('namespace App\Http\Controllers')
                ->unless(
                    str_contains($controller, '/Admin/'),
                    fn ($e) => $e->toContain('namespace App\Http\Controllers;'),
                    fn ($e) => $e->toContain('namespace App\Http\Controllers\Admin;'),
                );
        }
    });

    it('has proper PHP declarations in models', function () use ($examplePath): void {
        $models = glob($examplePath . '/app/Models/*.php');

        foreach ($models as $model) {
            $content = file_get_contents($model);
            $filename = basename($model);

            expect($content)
                ->toContain('<?php')
                ->toContain('namespace App\Models;');

            // User model extends Authenticatable, others extend Model
            if ('User.php' !== $filename) {
                expect($content)->toContain('use Illuminate\Database\Eloquent\Model;');
            } else {
                expect($content)->toContain('use Illuminate\Foundation\Auth\User as Authenticatable;');
            }
        }
    });

    it('has proper PHP declarations in requests', function () use ($examplePath): void {
        $requests = glob($examplePath . '/app/Http/Requests/*.php');

        foreach ($requests as $request) {
            $content = file_get_contents($request);

            expect($content)
                ->toContain('<?php')
                ->toContain('declare(strict_types=1);')
                ->toContain('namespace App\Http\Requests;')
                ->toContain('use Illuminate\Foundation\Http\FormRequest;');
        }
    });

    it('controllers use proper return type declarations', function () use ($examplePath): void {
        $controllers = [
            $examplePath . '/app/Http/Controllers/OrganizationController.php',
            $examplePath . '/app/Http/Controllers/TeamController.php',
            $examplePath . '/app/Http/Controllers/FolderController.php',
        ];

        foreach ($controllers as $controller) {
            $content = file_get_contents($controller);

            expect($content)
                ->toContain('): View')
                ->toContain('): RedirectResponse')
                ->toContain('use Illuminate\View\View;')
                ->toContain('use Illuminate\Http\{RedirectResponse');
        }
    });

    it('migrations follow Laravel naming conventions', function () use ($examplePath): void {
        $migrations = glob($examplePath . '/database/migrations/*.php');

        foreach ($migrations as $migration) {
            $filename = basename($migration);

            expect($filename)
                ->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_\w+\.php$/');
        }
    });

    it('routes file uses proper middleware syntax', function () use ($examplePath): void {
        $routesContent = file_get_contents($examplePath . '/routes/web.php');

        expect($routesContent)
            ->toContain("Route::middleware(['auth'])")
            ->toContain("Route::middleware(['openfga:")
            ->toContain("Route::prefix('")
            ->toContain("->name('")
            ->toContain('use Illuminate\Support\Facades\{Auth, Route};');
    });

    it('docker-compose.yml has valid structure', function () use ($examplePath): void {
        $dockerCompose = file_get_contents($examplePath . '/docker-compose.yml');

        expect($dockerCompose)
            ->toContain("version: '3.8'")
            ->toContain('services:')
            ->toContain('app:')
            ->toContain('webserver:')
            ->toContain('db:')
            ->toContain('openfga:')
            ->toContain('redis:')
            ->toContain('mailhog:')
            ->toContain('networks:')
            ->toContain('volumes:');
    });

    it('OpenFGA model.json has valid structure', function () use ($examplePath): void {
        $modelJson = file_get_contents($examplePath . '/openfga/model.json');
        $model = json_decode($modelJson, true);

        expect($model)
            ->toBeArray()
            ->toHaveKey('schema_version', '1.1')
            ->toHaveKey('type_definitions')
            ->and($model['type_definitions'])
            ->toBeArray()
            ->toHaveCount(6);

        $types = array_map(fn ($def) => $def['type'], $model['type_definitions']);

        expect($types)->toBe([
            'user',
            'organization',
            'department',
            'team',
            'folder',
            'document',
        ]);
    });

    it('README.md has comprehensive documentation', function () use ($examplePath): void {
        $readme = file_get_contents($examplePath . '/README.md');

        expect($readme)
            ->toContain('OpenFGA Laravel Example Application')
            ->toContain('## Overview')
            ->toContain('## Authorization Model')
            ->toContain('## Quick Start')
            ->toContain('### Option A: Docker Setup')
            ->toContain('### Option B: Manual Setup')
            ->toContain('./docker-setup.sh')
            ->toContain('composer require evansms/openfga-laravel')
            ->toContain('## Docker Management')
            ->toContain('docker compose');
    });

    it('install.sh has proper error handling', function () use ($examplePath): void {
        $installScript = file_get_contents($examplePath . '/install.sh');

        expect($installScript)
            ->toContain('set -e')
            ->toContain('#!/bin/bash')
            ->toContain('if [ ! -f "README.md" ]')
            ->toContain('if [ ! -f "artisan" ]')
            ->toContain('echo "❌ Error:')
            ->toContain('echo "✅')
            ->toContain('composer require evansims/openfga-laravel');
    });

    it('docker-setup.sh has proper structure', function () use ($examplePath): void {
        $dockerSetup = file_get_contents($examplePath . '/docker-setup.sh');

        expect($dockerSetup)
            ->toContain('#!/bin/bash')
            ->toContain('set -e')
            ->toContain('docker compose up -d')
            ->toContain('docker compose exec')
            ->toContain('curl -s -X POST http://localhost:8080/stores');
    });
});
