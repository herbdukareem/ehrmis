<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->string('vendor_no', 80);
            $table->string('name');
            $table->string('vendor_type', 60)->default('supplier')->index();
            $table->string('registration_number', 120)->nullable();
            $table->string('tax_identification_number', 120)->nullable();
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['mda_id', 'vendor_no'], 'vendors_mda_number_unique');
            $table->index(['mda_id', 'name'], 'vendors_mda_name_idx');
        });

        Schema::create('procurement_solicitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procurement_requisition_id')->nullable()->constrained('procurement_requisitions')->nullOnDelete();
            $table->string('solicitation_no', 80);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('procurement_method', 60)->index();
            $table->decimal('estimated_amount', 18, 2)->nullable();
            $table->string('currency', 10)->default('NGN');
            $table->date('issue_date')->nullable();
            $table->dateTime('closing_at')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['mda_id', 'solicitation_no'], 'procurement_solicitations_mda_number_unique');
            $table->index(['mda_id', 'procurement_method', 'status'], 'proc_solicitations_mda_method_status_idx');
        });

        Schema::create('procurement_bids', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procurement_solicitation_id')->constrained('procurement_solicitations')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('bid_no', 80);
            $table->decimal('quoted_amount', 18, 2);
            $table->string('currency', 10)->default('NGN');
            $table->timestamp('submitted_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 30)->default('submitted')->index();
            $table->decimal('technical_score', 8, 2)->nullable();
            $table->decimal('financial_score', 8, 2)->nullable();
            $table->decimal('total_score', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['mda_id', 'bid_no'], 'procurement_bids_mda_number_unique');
            $table->unique(['procurement_solicitation_id', 'vendor_id'], 'procurement_bids_solicitation_vendor_unique');
            $table->index(['mda_id', 'status'], 'procurement_bids_mda_status_idx');
        });

        Schema::create('procurement_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->string('documentable_type');
            $table->unsignedBigInteger('documentable_id');
            $table->string('title');
            $table->string('document_type', 60)->default('supporting_document')->index();
            $table->string('file_path')->nullable();
            $table->string('file_mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('external_url')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['documentable_type', 'documentable_id'], 'procurement_documents_documentable_idx');
            $table->index(['mda_id', 'document_type'], 'procurement_documents_mda_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_documents');
        Schema::dropIfExists('procurement_bids');
        Schema::dropIfExists('procurement_solicitations');
        Schema::dropIfExists('vendors');
    }
};
