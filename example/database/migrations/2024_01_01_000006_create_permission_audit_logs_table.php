<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // grant, revoke, check, list
            $table->string('relation'); // admin, manager, member, viewer, editor, owner
            $table->string('resource_type'); // organization, department, team, folder, document
            $table->string('resource_id');
            $table->string('target_user_id')->nullable(); // For grant/revoke actions
            $table->boolean('result')->nullable(); // For check actions
            $table->json('context')->nullable(); // Additional context
            $table->json('changes')->nullable(); // Before/after state
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('action');
            $table->index(['resource_type', 'resource_id']);
            $table->index('target_user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_audit_logs');
    }
};