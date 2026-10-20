<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('receipt_no', 80);
            $table->date('received_date')->nullable();
            $table->string('delivery_note_no', 120)->nullable();
            $table->string('inspection_status', 30)->default('pending')->index();
            $table->string('status', 30)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['mda_id', 'receipt_no'], 'procurement_receipts_mda_number_unique');
            $table->index(['mda_id', 'status', 'inspection_status'], 'proc_receipts_mda_status_inspection_idx');
            $table->index(['purchase_order_id', 'status'], 'proc_receipts_po_status_idx');
        });

        Schema::create('procurement_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procurement_receipt_id')->constrained('procurement_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $table->foreignId('budget_allocation_id')->nullable()->constrained('budget_allocations')->nullOnDelete();
            $table->foreignId('budget_commitment_id')->nullable()->constrained('budget_commitments')->nullOnDelete();
            $table->text('item_description');
            $table->decimal('quantity_received', 18, 4)->default(0);
            $table->decimal('quantity_accepted', 18, 4)->default(0);
            $table->decimal('quantity_rejected', 18, 4)->default(0);
            $table->string('unit', 50)->nullable();
            $table->decimal('unit_price', 18, 2)->nullable();
            $table->decimal('received_amount', 18, 2)->default(0);
            $table->decimal('accepted_amount', 18, 2)->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->text('remarks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['procurement_receipt_id', 'purchase_order_item_id'], 'proc_receipt_items_receipt_po_item_unique');
            $table->index(['mda_id', 'budget_allocation_id'], 'proc_receipt_items_mda_allocation_idx');
            $table->index(['purchase_order_item_id', 'status'], 'proc_receipt_items_po_item_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_receipt_items');
        Schema::dropIfExists('procurement_receipts');
    }
};
