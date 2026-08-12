<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workplans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('revision_no')->default(1);
            $table->foreignId('supersedes_workplan_id')->nullable()->constrained('workplans')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('amendment_reason')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
            $table->unique(['mda_id', 'year', 'revision_no'], 'workplans_mda_year_revision_unique');
            $table->index(['mda_id', 'year', 'status'], 'workplans_mda_year_status_idx');
        });

        Schema::create('workplan_objectives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 100);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 50)->nullable();
            $table->decimal('performance_weight', 8, 4)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['workplan_id', 'code'], 'workplan_objectives_workplan_code_unique');
            $table->index(['mda_id', 'department_id'], 'workplan_objectives_mda_department_idx');
        });

        Schema::create('workplan_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplan_objective_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsible_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('activity_code', 100);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('expected_output')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('planned_cost', 18, 2)->nullable();
            $table->string('funding_source')->nullable();
            $table->string('status', 30)->default('not_started')->index();
            $table->decimal('performance_weight', 8, 4)->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['workplan_objective_id', 'activity_code'], 'workplan_activities_objective_code_unique');
            $table->index(['mda_id', 'department_id', 'status'], 'workplan_activities_mda_department_status_idx');
            $table->index(['responsible_staff_id', 'status', 'end_date'], 'workplan_activities_staff_status_end_idx');
        });

        Schema::create('workplan_activity_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplan_activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('role', 30)->default('support');
            $table->timestamps();
            $table->unique(['workplan_activity_id', 'staff_id'], 'workplan_activity_assignments_activity_staff_unique');
            $table->index(['mda_id', 'staff_id'], 'workplan_activity_assignments_mda_staff_idx');
        });

        Schema::create('workplan_indicators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplan_activity_id')->constrained()->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('indicator');
            $table->string('unit')->nullable();
            $table->decimal('baseline_value', 18, 4)->nullable();
            $table->decimal('annual_target_value', 18, 4)->nullable();
            $table->string('target_mode', 40);
            $table->string('direction', 20);
            $table->decimal('weight', 8, 4)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();
            $table->unique(['workplan_activity_id', 'code'], 'workplan_indicators_activity_code_unique');
        });

        Schema::create('workplan_indicator_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workplan_indicator_id')->constrained()->cascadeOnDelete();
            $table->string('period', 20);
            $table->decimal('target_value', 18, 4)->nullable();
            $table->timestamps();
            $table->unique(['workplan_indicator_id', 'period'], 'workplan_indicator_targets_indicator_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workplan_indicator_targets');
        Schema::dropIfExists('workplan_indicators');
        Schema::dropIfExists('workplan_activity_assignments');
        Schema::dropIfExists('workplan_activities');
        Schema::dropIfExists('workplan_objectives');
        Schema::dropIfExists('workplans');
    }
};
