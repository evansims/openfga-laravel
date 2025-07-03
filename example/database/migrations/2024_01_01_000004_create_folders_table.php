<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->morphs('parent'); // Can belong to organization, department, team, or another folder
            $table->foreignId('parent_folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->string('path')->nullable(); // Full path for quick lookups
            $table->integer('level')->default(0); // Nesting level
            $table->timestamps();
            
            $table->index(['parent_type', 'parent_id']);
            $table->index('parent_folder_id');
            $table->index('path');
            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};