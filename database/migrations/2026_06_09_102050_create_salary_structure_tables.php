<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('salary_structure_rate_allowances');
        Schema::dropIfExists('salary_structure_rates');
        Schema::dropIfExists('allowance_types');

        Schema::create('allowance_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('salary_structure_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('salary_scale_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->unsignedTinyInteger('step');
            $table->string('grade_code', 50)->default('');
            $table->string('detail')->nullable();
            $table->decimal('basic_salary', 15, 2);
            $table->decimal('legacy_gross_salary', 15, 2)->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['salary_scale_id', 'level', 'step', 'grade_code'], 'salary_structure_rate_identity_unique');
        });

        Schema::create('salary_structure_rate_allowances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('salary_structure_rate_id');
            $table->unsignedBigInteger('allowance_type_id');
            $table->decimal('amount', 15, 2);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();

            $table->foreign('salary_structure_rate_id', 'ssra_rate_fk')
                ->references('id')
                ->on('salary_structure_rates')
                ->cascadeOnDelete();
            $table->foreign('allowance_type_id', 'ssra_allowance_fk')
                ->references('id')
                ->on('allowance_types')
                ->cascadeOnDelete();
            $table->unique(['salary_structure_rate_id', 'allowance_type_id'], 'salary_structure_rate_allowance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_structure_rate_allowances');
        Schema::dropIfExists('salary_structure_rates');
        Schema::dropIfExists('allowance_types');
    }
};
