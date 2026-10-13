<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procurement_solicitation_id')->constrained('procurement_solicitations')->cascadeOnDelete();
            $table->foreignId('procurement_bid_id')->constrained('procurement_bids')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->decimal('technical_score', 8, 2)->nullable();
            $table->decimal('financial_score', 8, 2)->nullable();
            $table->decimal('total_score', 8, 2)->nullable();
            $table->string('status', 30)->default('evaluated')->index();
            $table->boolean('recommended')->default(false)->index();
            $table->unsignedInteger('rank')->nullable();
            $table->text('recommendation_notes')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable();
            $table->json('criteria_scores')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['procurement_solicitation_id', 'procurement_bid_id'], 'proc_evaluations_solicitation_bid_unique');
            $table->index(['mda_id', 'status', 'recommended'], 'proc_evaluations_mda_status_recommended_idx');
        });

        Schema::create('procurement_awards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procurement_solicitation_id')->constrained('procurement_solicitations')->cascadeOnDelete();
            $table->foreignId('procurement_bid_id')->constrained('procurement_bids')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('procurement_evaluation_id')->nullable()->constrained('procurement_evaluations')->nullOnDelete();
            $table->string('award_no', 80);
            $table->decimal('award_amount', 18, 2);
            $table->string('currency', 10)->default('NGN');
            $table->string('status', 30)->default('recommended')->index();
            $table->text('recommendation_summary')->nullable();
            $table->foreignId('recommended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recommended_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['mda_id', 'award_no'], 'procurement_awards_mda_number_unique');
            $table->unique(['procurement_solicitation_id'], 'procurement_awards_solicitation_unique');
            $table->index(['mda_id', 'status'], 'procurement_awards_mda_status_idx');
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procurement_award_id')->constrained('procurement_awards')->cascadeOnDelete();
            $table->foreignId('procurement_solicitation_id')->constrained('procurement_solicitations')->restrictOnDelete();
            $table->foreignId('procurement_requisition_id')->nullable()->constrained('procurement_requisitions')->nullOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('po_no', 80);
            $table->string('title');
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('currency', 10)->default('NGN');
            $table->string('status', 30)->default('draft')->index();
            $table->date('order_date')->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['mda_id', 'po_no'], 'purchase_orders_mda_number_unique');
            $table->unique(['procurement_award_id'], 'purchase_orders_award_unique');
            $table->index(['mda_id', 'status'], 'purchase_orders_mda_status_idx');
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('procurement_requisition_line_id')->nullable()->constrained('procurement_requisition_lines')->nullOnDelete();
            $table->foreignId('budget_allocation_id')->nullable()->constrained('budget_allocations')->nullOnDelete();
            $table->foreignId('budget_commitment_id')->nullable()->constrained('budget_commitments')->nullOnDelete();
            $table->text('item_description');
            $table->text('specification')->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->string('unit', 50)->nullable();
            $table->decimal('unit_price', 18, 2)->nullable();
            $table->decimal('total_amount', 18, 2);
            $table->string('status', 30)->default('ordered')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['mda_id', 'budget_allocation_id'], 'po_items_mda_allocation_idx');
            $table->index(['purchase_order_id', 'status'], 'po_items_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('procurement_awards');
        Schema::dropIfExists('procurement_evaluations');
    }
};
