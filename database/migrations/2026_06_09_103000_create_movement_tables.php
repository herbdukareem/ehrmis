<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movement_workbooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['mda_id', 'year'], 'movement_workbooks_mda_year_unique');
        });

        Schema::create('movement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workbook_id')->constrained('movement_workbooks')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('current_employment_id')->nullable()->constrained('staff_employments')->nullOnDelete();
            $table->foreignId('current_salary_placement_id')->nullable()->constrained('staff_salary_placements')->nullOnDelete();
            $table->foreignId('current_salary_scale_id')->nullable()->constrained('salary_scales')->nullOnDelete();
            $table->foreignId('proposed_salary_scale_id')->nullable()->constrained('salary_scales')->nullOnDelete();
            $table->string('selection_state', 30)->default('included')->index();
            $table->string('eligibility_status', 30)->default('not_due')->index();
            $table->string('retirement_status', 30)->default('active')->index();
            $table->unsignedTinyInteger('retirement_month')->nullable();
            $table->unsignedTinyInteger('current_level')->nullable();
            $table->unsignedTinyInteger('current_step')->nullable();
            $table->unsignedTinyInteger('proposed_level')->nullable();
            $table->unsignedTinyInteger('proposed_step')->nullable();
            $table->json('current_amounts')->nullable();
            $table->json('proposed_amounts')->nullable();
            $table->json('decision_trace')->nullable();
            $table->string('calculation_source', 50)->default('salary_calculation_service');
            $table->timestamps();

            $table->unique(['workbook_id', 'staff_id'], 'movement_lines_workbook_staff_unique');
            $table->index(['workbook_id', 'eligibility_status'], 'movement_lines_workbook_eligibility_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_lines');
        Schema::dropIfExists('movement_workbooks');
    }
};
