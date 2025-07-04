<?php

declare(strict_types=1);
describe('Example Application Migrations', function (): void {
    $examplePath = __DIR__ . '/../../example';

    it('organizations migration has correct structure', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/database/migrations/2024_01_01_000001_create_organizations_table.php');
        
        expect($content)
            ->toContain("Schema::create('organizations'")
            ->toContain('$table->id();')
            ->toContain("\$table->string('name');")
            ->toContain("\$table->text('description')->nullable();")
            ->toContain("\$table->string('slug')->unique();")
            ->toContain("\$table->json('settings')->nullable();")
            ->toContain('$table->timestamps();')
            ->toContain("\$table->index('slug');")
            ->toContain("\$table->index('created_at');");
        
        // Check pivot table
        expect($content)
            ->toContain("Schema::create('organization_user'")
            ->toContain("\$table->foreignId('organization_id')->constrained()->cascadeOnDelete();")
            ->toContain("\$table->foreignId('user_id')->constrained()->cascadeOnDelete();")
            ->toContain("\$table->string('role')->default('member');")
            ->toContain("\$table->unique(['organization_id', 'user_id']);");
    });

    it('departments migration has organization relationship', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/database/migrations/2024_01_01_000002_create_departments_table.php');
        
        expect($content)
            ->toContain("Schema::create('departments'")
            ->toContain("\$table->foreignId('organization_id')->constrained()->cascadeOnDelete();")
            ->toContain("\$table->string('code')->nullable();")
            ->toContain("\$table->unique(['organization_id', 'code']);")
            ->toContain("Schema::create('department_user'");
    });

    it('teams migration has department relationship', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/database/migrations/2024_01_01_000003_create_teams_table.php');
        
        expect($content)
            ->toContain("Schema::create('teams'")
            ->toContain("\$table->foreignId('department_id')->constrained()->cascadeOnDelete();")
            ->toContain("\$table->unique(['department_id', 'code']);")
            ->toContain("Schema::create('team_user'");
    });

    it('folders migration has polymorphic parent', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/database/migrations/2024_01_01_000004_create_folders_table.php');
        
        expect($content)
            ->toContain('$table->morphs(\'parent\');')
            ->toContain("\$table->foreignId('parent_folder_id')->nullable()->constrained('folders')->nullOnDelete();")
            ->toContain("\$table->string('path')->nullable();")
            ->toContain("\$table->integer('level')->default(0);")
            ->toContain("\$table->index(['parent_type', 'parent_id']);")
            ->toContain("\$table->index('path');");
    });

    it('documents migration has comprehensive fields', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/database/migrations/2024_01_01_000005_create_documents_table.php');
        
        expect($content)
            ->toContain("Schema::create('documents'")
            ->toContain("\$table->string('title');")
            ->toContain('$table->text(\'content\');')
            ->toContain("\$table->enum('type', ['text', 'markdown', 'richtext'])->default('text');")
            ->toContain("\$table->enum('status', ['draft', 'published', 'archived'])->default('draft');")
            ->toContain("\$table->foreignId('owner_id')->constrained('users')->restrictOnDelete();")
            ->toContain("\$table->foreignId('folder_id')->nullable()->constrained()->nullOnDelete();")
            ->toContain("\$table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();")
            ->toContain("\$table->integer('version')->default(1);")
            ->toContain("\$table->bigInteger('size_bytes')->default(0);")
            ->toContain("\$table->json('tags')->nullable();")
            ->toContain("\$table->fullText(['title', 'content']);");
    });

    it('documents migration includes version tracking', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/database/migrations/2024_01_01_000005_create_documents_table.php');
        
        expect($content)
            ->toContain("Schema::create('document_versions'")
            ->toContain("\$table->foreignId('document_id')->constrained()->cascadeOnDelete();")
            ->toContain("\$table->foreignId('user_id')->constrained()->restrictOnDelete();")
            ->toContain("\$table->integer('version');")
            ->toContain("\$table->text('content');")
            ->toContain("\$table->string('version_notes')->nullable();")
            ->toContain("\$table->unique(['document_id', 'version']);");
    });

    it('documents migration includes share tracking', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/database/migrations/2024_01_01_000005_create_documents_table.php');
        
        expect($content)
            ->toContain("Schema::create('document_shares'")
            ->toContain("\$table->foreignId('shared_by')->constrained('users')->restrictOnDelete();")
            ->toContain("\$table->string('permission')->default('viewer');")
            ->toContain("\$table->timestamp('expires_at')->nullable();")
            ->toContain("\$table->unique(['document_id', 'user_id']);");
    });

    it('audit logs migration has proper structure', function () use ($examplePath): void {
        $content = file_get_contents($examplePath . '/database/migrations/2024_01_01_000006_create_permission_audit_logs_table.php');
        
        expect($content)
            ->toContain("Schema::create('permission_audit_logs'")
            ->toContain("\$table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();")
            ->toContain("\$table->string('action');")
            ->toContain("\$table->string('relation');")
            ->toContain("\$table->string('resource_type');")
            ->toContain("\$table->string('resource_id');")
            ->toContain("\$table->string('target_user_id')->nullable();")
            ->toContain("\$table->boolean('result')->nullable();")
            ->toContain("\$table->json('context')->nullable();")
            ->toContain("\$table->json('changes')->nullable();")
            ->toContain("\$table->string('ip_address')->nullable();");
    });

    it('all migrations have proper indexes', function () use ($examplePath): void {
        $migrations = glob($examplePath . '/database/migrations/*.php');
        
        foreach ($migrations as $migration) {
            $content = file_get_contents($migration);
            
            if (str_contains($migration, 'organizations')) {
                expect($content)->toContain('$table->index(');
            }
            
            if (str_contains($migration, 'documents')) {
                expect($content)
                    ->toContain("\$table->index('owner_id');")
                    ->toContain("\$table->index('status');")
                    ->toContain('$table->fullText(');
            }
            
            if (str_contains($migration, 'audit_logs')) {
                expect($content)
                    ->toContain("\$table->index('user_id');")
                    ->toContain("\$table->index('action');")
                    ->toContain("\$table->index('created_at');");
            }
        }
    });
});