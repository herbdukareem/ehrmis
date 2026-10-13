<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_cycle_id')->nullable()->constrained('budget_cycles')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->string('source_type', 40)->default('manual')->index();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['mda_id', 'year', 'status'], 'procurement_plans_mda_year_status_idx');
        });

        Schema::create('procurement_plan_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procurement_plan_id')->constrained('procurement_plans')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('budget_allocation_id')->nullable()->constrained('budget_allocations')->nullOnDelete();
            $table->foreignId('workplan_activity_id')->nullable()->constrained('workplan_activities')->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('item_description');
            $table->text('specification')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->string('unit', 50)->nullable();
            $table->decimal('estimated_unit_cost', 18, 2)->nullable();
            $table->decimal('estimated_amount', 18, 2);
            $table->string('procurement_method', 60)->default('to_be_determined')->index();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->string('status', 30)->default('planned')->index();
            $table->string('priority', 30)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['mda_id', 'department_id', 'status'], 'procurement_lines_mda_department_status_idx');
            $table->index(['procurement_plan_id', 'status'], 'procurement_lines_plan_status_idx');
            $table->index(['budget_allocation_id', 'status'], 'procurement_lines_allocation_status_idx');
            $table->index(['source_type', 'source_id'], 'procurement_lines_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_plan_lines');
        Schema::dropIfExists('procurement_plans');
    }
};
