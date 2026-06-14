<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_workbooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movement_workbook_id')->constrained('movement_workbooks')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['movement_workbook_id'], 'budget_workbooks_movement_workbook_unique');
            $table->unique(['mda_id', 'year'], 'budget_workbooks_mda_year_unique');
        });

        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workbook_id')->constrained('budget_workbooks')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salary_scale_id')->nullable()->constrained('salary_scales')->nullOnDelete();
            $table->unsignedTinyInteger('level')->nullable();
            $table->unsignedInteger('staff_count')->default(0);
            $table->unsignedInteger('retiring_count')->default(0);
            $table->decimal('current_gross_total', 18, 2)->default(0);
            $table->decimal('proposed_gross_total', 18, 2)->default(0);
            $table->decimal('variance_total', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(
                ['workbook_id', 'department_id', 'salary_scale_id', 'level'],
                'budget_lines_workbook_department_scale_level_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budget_workbooks');
    }
};
