<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qualification_types')) {
            Schema::create('qualification_types', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('qualification_scale_ceilings')) {
            Schema::create('qualification_scale_ceilings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('qualification_type_id')->constrained()->cascadeOnDelete();
                $table->foreignId('salary_scale_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('max_level');
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();

                $table->unique(['qualification_type_id', 'salary_scale_id'], 'qual_scale_ceilings_unique');
            });
        }

        if (! Schema::hasTable('promotion_policies')) {
            Schema::create('promotion_policies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('salary_scale_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('min_level');
                $table->unsignedTinyInteger('max_level');
                $table->unsignedTinyInteger('required_years');
                $table->string('policy_type', 30)->default('normal');
                $table->text('description')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();

                $table->unique(['salary_scale_id', 'min_level', 'max_level', 'policy_type'], 'promo_policy_scale_range_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_policies');
        Schema::dropIfExists('qualification_scale_ceilings');
        Schema::dropIfExists('qualification_types');
    }
};
