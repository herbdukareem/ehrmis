<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_requisitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procurement_plan_id')->nullable()->constrained('procurement_plans')->nullOnDelete();
            $table->foreignId('budget_cycle_id')->nullable()->constrained('budget_cycles')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requisition_no', 80);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('justification')->nullable();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('status', 30)->default('draft')->index();
            $table->string('budget_review_status', 30)->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('budget_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('budget_reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['mda_id', 'requisition_no'], 'procurement_requisitions_mda_number_unique');
            $table->index(['mda_id', 'status', 'budget_review_status'], 'procurement_requisitions_mda_status_review_idx');
        });

        Schema::create('procurement_requisition_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procurement_requisition_id')->constrained('procurement_requisitions')->cascadeOnDelete();
            $table->foreignId('procurement_plan_line_id')->nullable()->constrained('procurement_plan_lines')->nullOnDelete();
            $table->foreignId('budget_allocation_id')->nullable()->constrained('budget_allocations')->nullOnDelete();
            $table->foreignId('budget_commitment_id')->nullable()->constrained('budget_commitments')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->text('item_description');
            $table->text('specification')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->string('unit', 50)->nullable();
            $table->decimal('estimated_unit_cost', 18, 2)->nullable();
            $table->decimal('estimated_amount', 18, 2);
            $table->string('status', 30)->default('draft')->index();
            $table->string('budget_check_status', 30)->default('pending')->index();
            $table->json('budget_check_summary')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['mda_id', 'budget_allocation_id', 'budget_check_status'], 'proc_req_lines_mda_allocation_budget_idx');
            $table->index(['procurement_requisition_id', 'status'], 'proc_req_lines_requisition_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_requisition_lines');
        Schema::dropIfExists('procurement_requisitions');
    }
};
