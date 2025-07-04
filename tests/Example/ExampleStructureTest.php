<?php

declare(strict_types=1);
describe('Example Application Structure', function (): void {
    $examplePath = __DIR__ . '/../../example';

    it('has all required directories', function () use ($examplePath): void {
        expect($examplePath)->toBeDirectory()
            ->and($examplePath . '/app')->toBeDirectory()
            ->and($examplePath . '/app/Http')->toBeDirectory()
            ->and($examplePath . '/app/Http/Controllers')->toBeDirectory()
            ->and($examplePath . '/app/Http/Controllers/Admin')->toBeDirectory()
            ->and($examplePath . '/app/Http/Requests')->toBeDirectory()
            ->and($examplePath . '/app/Models')->toBeDirectory()
            ->and($examplePath . '/database')->toBeDirectory()
            ->and($examplePath . '/database/migrations')->toBeDirectory()
            ->and($examplePath . '/database/seeders')->toBeDirectory()
            ->and($examplePath . '/docker')->toBeDirectory()
            ->and($examplePath . '/openfga')->toBeDirectory()
            ->and($examplePath . '/resources')->toBeDirectory()
            ->and($examplePath . '/resources/views')->toBeDirectory()
            ->and($examplePath . '/routes')->toBeDirectory()
            ->and($examplePath . '/tests')->toBeDirectory()
            ->and($examplePath . '/tests/Feature')->toBeDirectory();
    });

    it('has all required configuration files', function () use ($examplePath): void {
        expect($examplePath . '/README.md')->toBeFile()
            ->and($examplePath . '/docker-compose.yml')->toBeFile()
            ->and($examplePath . '/Dockerfile')->toBeFile()
            ->and($examplePath . '/install.sh')->toBeFile()
            ->and($examplePath . '/docker-setup.sh')->toBeFile();
    });

    it('has executable scripts with correct permissions', function () use ($examplePath): void {
        expect($examplePath . '/install.sh')->toBeFile()
            ->and($examplePath . '/docker-setup.sh')->toBeFile();
        
        // Check if files are executable
        $installPerms = fileperms($examplePath . '/install.sh');
        $dockerPerms = fileperms($examplePath . '/docker-setup.sh');
        
        expect($installPerms & 0111)->toBeGreaterThan(0)
            ->and($dockerPerms & 0111)->toBeGreaterThan(0);
    });

    it('has all required controllers', function () use ($examplePath): void {
        expect($examplePath . '/app/Http/Controllers/DocumentController.php')->toBeFile()
            ->and($examplePath . '/app/Http/Controllers/OrganizationController.php')->toBeFile()
            ->and($examplePath . '/app/Http/Controllers/TeamController.php')->toBeFile()
            ->and($examplePath . '/app/Http/Controllers/FolderController.php')->toBeFile()
            ->and($examplePath . '/app/Http/Controllers/Admin/UserController.php')->toBeFile()
            ->and($examplePath . '/app/Http/Controllers/Admin/AuditController.php')->toBeFile();
    });

    it('has all required models', function () use ($examplePath): void {
        expect($examplePath . '/app/Models/User.php')->toBeFile()
            ->and($examplePath . '/app/Models/Organization.php')->toBeFile()
            ->and($examplePath . '/app/Models/Department.php')->toBeFile()
            ->and($examplePath . '/app/Models/Team.php')->toBeFile()
            ->and($examplePath . '/app/Models/Document.php')->toBeFile()
            ->and($examplePath . '/app/Models/Folder.php')->toBeFile();
    });

    it('has all required request classes', function () use ($examplePath): void {
        expect($examplePath . '/app/Http/Requests/StoreDocumentRequest.php')->toBeFile()
            ->and($examplePath . '/app/Http/Requests/UpdateDocumentRequest.php')->toBeFile();
    });

    it('has all required migrations', function () use ($examplePath): void {
        $migrationsPath = $examplePath . '/database/migrations';
        $migrations = glob($migrationsPath . '/*.php');
        
        expect($migrations)->toHaveCount(6);
        
        $migrationNames = array_map(fn($path) => basename($path), $migrations);
        
        $hasOrganizations = false;
        $hasDepartments = false;
        $hasTeams = false;
        $hasFolders = false;
        $hasDocuments = false;
        $hasAuditLogs = false;
        
        foreach ($migrationNames as $name) {
            if (str_contains($name, 'create_organizations_table')) $hasOrganizations = true;
            if (str_contains($name, 'create_departments_table')) $hasDepartments = true;
            if (str_contains($name, 'create_teams_table')) $hasTeams = true;
            if (str_contains($name, 'create_folders_table')) $hasFolders = true;
            if (str_contains($name, 'create_documents_table')) $hasDocuments = true;
            if (str_contains($name, 'create_permission_audit_logs_table')) $hasAuditLogs = true;
        }
        
        expect($hasOrganizations)->toBeTrue()
            ->and($hasDepartments)->toBeTrue()
            ->and($hasTeams)->toBeTrue()
            ->and($hasFolders)->toBeTrue()
            ->and($hasDocuments)->toBeTrue()
            ->and($hasAuditLogs)->toBeTrue();
    });

    it('has OpenFGA model files', function () use ($examplePath): void {
        expect($examplePath . '/openfga/model.fga')->toBeFile()
            ->and($examplePath . '/openfga/model.json')->toBeFile();
    });

    it('has Docker configuration files', function () use ($examplePath): void {
        expect($examplePath . '/docker/nginx/conf.d/app.conf')->toBeFile()
            ->and($examplePath . '/docker/php/local.ini')->toBeFile()
            ->and($examplePath . '/docker/mysql/my.cnf')->toBeFile();
    });

    it('has example seeder', function () use ($examplePath): void {
        expect($examplePath . '/database/seeders/ExampleSeeder.php')->toBeFile();
    });

    it('has routes file', function () use ($examplePath): void {
        expect($examplePath . '/routes/web.php')->toBeFile();
    });

    it('has example views', function () use ($examplePath): void {
        expect($examplePath . '/resources/views/documents/show.blade.php')->toBeFile();
    });

    it('has feature tests', function () use ($examplePath): void {
        expect($examplePath . '/tests/Feature/DocumentManagementTest.php')->toBeFile();
    });
});