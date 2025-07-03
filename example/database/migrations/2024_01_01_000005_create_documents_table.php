<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->enum('type', ['text', 'markdown', 'richtext'])->default('text');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('version')->default(1);
            $table->bigInteger('size_bytes')->default(0);
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            
            $table->index('owner_id');
            $table->index('folder_id');
            $table->index('team_id');
            $table->index('organization_id');
            $table->index('department_id');
            $table->index('status');
            $table->index('published_at');
            $table->fullText(['title', 'content']);
        });

        // Document versions table for history
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->integer('version');
            $table->text('content');
            $table->string('version_notes')->nullable();
            $table->bigInteger('size_bytes')->default(0);
            $table->timestamps();
            
            $table->unique(['document_id', 'version']);
            $table->index(['document_id', 'created_at']);
        });

        // Document shares for tracking shared access
        Schema::create('document_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by')->constrained('users')->restrictOnDelete();
            $table->string('permission')->default('viewer');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            $table->unique(['document_id', 'user_id']);
            $table->index(['user_id', 'document_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_shares');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};