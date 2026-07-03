<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('icon')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('mda_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->constrained('mdas')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['mda_id', 'module_id']);
            $table->index(['mda_id', 'enabled']);
        });

        Schema::create('module_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->string('permission_name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['module_id', 'permission_name']);
            $table->index('permission_name');
        });

        Schema::create('module_role_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->nullable()->constrained('modules')->nullOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->string('scope_type', 20)->default('mda');
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('module_role_template_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_role_template_id')->constrained('module_role_templates')->cascadeOnDelete();
            $table->string('permission_name');
            $table->timestamps();

            $table->unique(['module_role_template_id', 'permission_name'], 'module_role_template_permission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_role_template_permissions');
        Schema::dropIfExists('module_role_templates');
        Schema::dropIfExists('module_permissions');
        Schema::dropIfExists('mda_modules');
        Schema::dropIfExists('modules');
    }
};
