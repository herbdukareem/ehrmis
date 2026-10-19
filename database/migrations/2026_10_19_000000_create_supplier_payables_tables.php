<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('invoice_no', 120);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3);
            $table->decimal('total_amount', 18, 2);
            $table->string('status', 30)->default('draft')->index();
            $table->text('notes')->nullable();
            foreach (['prepared', 'submitted', 'approved', 'rejected', 'cancelled'] as $action) {
                $table->foreignId($action.'_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp($action.'_at')->nullable();
            }
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['mda_id', 'vendor_id', 'invoice_no'], 'supplier_invoice_vendor_number_unique');
            $table->index(['mda_id', 'status', 'due_date'], 'supplier_invoice_mda_status_due_idx');
        });

        Schema::create('supplier_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('procurement_receipt_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->text('item_description');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('total_amount', 18, 2);
            $table->timestamps();
            $table->unique(['supplier_invoice_id', 'procurement_receipt_item_id'], 'supplier_invoice_receipt_item_unique');
            $table->index(['mda_id', 'procurement_receipt_item_id'], 'supplier_invoice_item_mda_receipt_idx');
        });

        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('payment_no', 80);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->string('method', 30);
            $table->string('status', 30)->default('draft')->index();
            $table->string('payment_reference', 160)->nullable();
            $table->date('paid_date')->nullable();
            $table->text('notes')->nullable();
            foreach (['prepared', 'submitted', 'approved', 'paid', 'rejected', 'cancelled'] as $action) {
                $table->foreignId($action.'_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp($action.'_at')->nullable();
            }
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['mda_id', 'request_key']);
            $table->unique(['mda_id', 'payment_no']);
            $table->unique(['mda_id', 'payment_reference'], 'supplier_payment_mda_reference_unique');
            $table->index(['supplier_invoice_id', 'status'], 'supplier_payment_invoice_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_invoice_items');
        Schema::dropIfExists('supplier_invoices');
    }
};
