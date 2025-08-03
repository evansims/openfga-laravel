<?php

declare(strict_types=1);
describe('Example Application Integration', function (): void {
    $examplePath = __DIR__ . '/../../example';

    it('routes file integrates with all controllers', function () use ($examplePath): void {
        $routesContent = file_get_contents($examplePath . '/routes/web.php');

        // Check controller imports
        expect($routesContent)
            ->toContain('use App\Http\Controllers\DocumentController;')
            ->toContain('use App\Http\Controllers\OrganizationController;')
            ->toContain('use App\Http\Controllers\TeamController;')
            ->toContain('use App\Http\Controllers\FolderController;');

        // Check document routes
        expect($routesContent)
            ->toContain('[DocumentController::class, \'index\']')
            ->toContain('[DocumentController::class, \'create\']')
            ->toContain('[DocumentController::class, \'store\']')
            ->toContain('[DocumentController::class, \'show\']')
            ->toContain('[DocumentController::class, \'edit\']')
            ->toContain('[DocumentController::class, \'update\']')
            ->toContain('[DocumentController::class, \'destroy\']');

        // Check organization routes
        expect($routesContent)
            ->toContain('[OrganizationController::class, \'index\']')
            ->toContain('[OrganizationController::class, \'show\']')
            ->toContain('[OrganizationController::class, \'members\']')
            ->toContain('[OrganizationController::class, \'createDepartment\']');

        // Check team routes
        expect($routesContent)
            ->toContain('[TeamController::class, \'index\']')
            ->toContain('[TeamController::class, \'show\']')
            ->toContain('[TeamController::class, \'documents\']')
            ->toContain('[TeamController::class, \'addMember\']');

        // Check folder routes
        expect($routesContent)
            ->toContain('[FolderController::class, \'index\']')
            ->toContain('[FolderController::class, \'show\']')
            ->toContain('[FolderController::class, \'createDocument\']')
            ->toContain('[FolderController::class, \'createSubfolder\']');
    });

    it('routes use OpenFGA middleware correctly', function () use ($examplePath): void {
        $routesContent = file_get_contents($examplePath . '/routes/web.php');

        // Document permissions
        expect($routesContent)
            ->toContain("middleware(['openfga:viewer,document:{document}'])")
            ->toContain("middleware(['openfga:editor,document:{document}'])")
            ->toContain("middleware(['openfga:owner,document:{document}'])");

        // Organization permissions
        expect($routesContent)
            ->toContain("middleware(['openfga:member,organization:{organization}'])")
            ->toContain("middleware(['openfga:manager,organization:{organization}'])")
            ->toContain("middleware(['openfga:admin,organization:{organization}'])");

        // Team permissions
        expect($routesContent)
            ->toContain("middleware(['openfga:member,team:{team}'])")
            ->toContain("middleware(['openfga:lead,team:{team}'])");

        // Folder permissions
        expect($routesContent)
            ->toContain("middleware(['openfga:viewer,folder:{folder}'])")
            ->toContain("middleware(['openfga:editor,folder:{folder}'])")
            ->toContain("middleware(['openfga:admin,folder:{folder}'])");

        // Advanced middleware patterns
        expect($routesContent)
            ->toContain("middleware(['openfga.any:admin|manager,organization:{organization}'])")
            ->toContain("middleware(['openfga.all:editor,document:{document}', 'openfga.all:member,team:{team}'])");
    });

    it('admin routes use proper namespace', function () use ($examplePath): void {
        $routesContent = file_get_contents($examplePath . '/routes/web.php');

        expect($routesContent)
            ->toContain("Route::prefix('admin')->name('admin.')")
            ->toContain("middleware(['auth', 'openfga:admin,system:global'])")
            ->toContain('[App\Http\Controllers\Admin\UserController::class')
            ->toContain('[App\Http\Controllers\Admin\AuditController::class');
    });

    it('API endpoints are properly defined', function () use ($examplePath): void {
        $routesContent = file_get_contents($examplePath . '/routes/web.php');

        expect($routesContent)
            ->toContain("Route::prefix('api')->name('api.')")
            ->toContain("Route::post('/permissions/check'")
            ->toContain('OpenFGA\Laravel\Facades\OpenFga::batchCheck($request->checks)')
            ->toContain("Route::get('/users/search'")
            ->toContain('App\Models\User::where');
    });

    it('models integrate with seeders properly', function () use ($examplePath): void {
        $seederContent = file_get_contents($examplePath . '/database/seeders/ExampleSeeder.php');

        expect($seederContent)
            ->toContain('use App\Models\Department;')
            ->toContain('use App\Models\Document;')
            ->toContain('use App\Models\Folder;')
            ->toContain('use App\Models\Organization;')
            ->toContain('use App\Models\Team;')
            ->toContain('use App\Models\User;')
            ->toContain('extends PermissionSeeder')
            ->toContain('Organization::create(')
            ->toContain('Department::create(')
            ->toContain('Team::create(')
            ->toContain('$this->writePermissions(');
    });

    it('feature test uses SDK correctly', function () use ($examplePath): void {
        $testContent = file_get_contents($examplePath . '/tests/Feature/DocumentManagementTest.php');

        expect($testContent)
            ->toContain('use OpenFGA\Laravel\Testing\{FakesOpenFga')
            ->toContain('use RefreshDatabase, FakesOpenFga')
            ->toContain('$this->fakeOpenFga();')
            ->toContain('OpenFga::check(')
            ->toContain('OpenFga::write(')
            ->toContain('OpenFga::batchCheck(')
            ->toContain('OpenFga::listObjects(');
    });

    it('Docker setup integrates all services', function () use ($examplePath): void {
        $dockerSetup = file_get_contents($examplePath . '/docker-setup.sh');

        expect($dockerSetup)
            ->toContain('docker compose up -d')
            ->toContain('docker compose exec -T app php artisan key:generate')
            ->toContain('docker compose exec -T app php artisan migrate --force')
            ->toContain('curl -s -X POST http://localhost:8080/stores')
            ->toContain('docker compose exec -T app php artisan db:seed --class=ExampleSeeder')
            ->toContain('docker compose exec -T app sed -i')
            ->toContain('OPENFGA_STORE_ID=')
            ->toContain('OPENFGA_MODEL_ID=');
    });

    it('install script integrates package correctly', function () use ($examplePath): void {
        $installScript = file_get_contents($examplePath . '/install.sh');

        expect($installScript)
            ->toContain('composer require evansims/openfga-laravel')
            ->toContain('php artisan vendor:publish --provider="OpenFGA\Laravel\OpenFgaServiceProvider"')
            ->toContain('curl -s -X POST "http://localhost:8080/stores/$STORE_ID/authorization-models"')
            ->toContain('-d @openfga/model.json')
            ->toContain('php artisan db:seed --class=ExampleSeeder');
    });
});
