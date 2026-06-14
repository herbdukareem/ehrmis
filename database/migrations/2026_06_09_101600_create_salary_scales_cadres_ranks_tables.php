<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_scales', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('min_level')->default(0);
            $table->unsignedTinyInteger('max_level')->default(0);
            $table->unsignedTinyInteger('min_step')->default(0);
            $table->unsignedTinyInteger('max_step')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cadres', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->foreignId('salary_scale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('legacy_department_name')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['name', 'salary_scale_id']);
        });

        Schema::create('ranks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->foreignId('cadre_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salary_scale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('level')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cadre_id', 'name', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranks');
        Schema::dropIfExists('cadres');
        Schema::dropIfExists('salary_scales');
    }
};
