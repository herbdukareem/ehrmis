<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('name')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['mda_id', 'year'], 'budget_cycles_mda_year_unique');
        });

        Schema::create('budget_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('budget_accounts')->nullOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->string('account_type', 40)->index();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['mda_id', 'code'], 'budget_accounts_mda_code_unique');
        });

        Schema::create('budget_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_cycle_id')->constrained('budget_cycles')->cascadeOnDelete();
            $table->foreignId('budget_account_id')->constrained('budget_accounts')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workplan_activity_id')->nullable()->constrained('workplan_activities')->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('approved_amount', 18, 2)->default(0);
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['mda_id', 'budget_cycle_id', 'status'], 'budget_allocations_mda_cycle_status_idx');
            $table->index(['source_type', 'source_id'], 'budget_allocations_source_idx');
        });

        Schema::create('budget_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_cycle_id')->constrained('budget_cycles')->cascadeOnDelete();
            $table->foreignId('budget_allocation_id')->constrained('budget_allocations')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->date('release_date');
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['mda_id', 'budget_cycle_id', 'status'], 'budget_releases_mda_cycle_status_idx');
        });

        Schema::create('budget_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_cycle_id')->constrained('budget_cycles')->cascadeOnDelete();
            $table->foreignId('budget_allocation_id')->constrained('budget_allocations')->cascadeOnDelete();
            $table->string('adjustment_type', 40)->default('increase')->index();
            $table->decimal('amount', 18, 2);
            $table->string('status', 30)->default('draft')->index();
            $table->text('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['mda_id', 'budget_cycle_id', 'status'], 'budget_adjustments_mda_cycle_status_idx');
        });

        Schema::create('budget_commitments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_cycle_id')->constrained('budget_cycles')->cascadeOnDelete();
            $table->foreignId('budget_allocation_id')->constrained('budget_allocations')->cascadeOnDelete();
            $table->string('commitment_no', 80)->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description');
            $table->decimal('amount', 18, 2);
            $table->string('status', 30)->default('active')->index();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['mda_id', 'commitment_no'], 'budget_commitments_mda_number_unique');
            $table->index(['mda_id', 'budget_cycle_id', 'status'], 'budget_commitments_mda_cycle_status_idx');
            $table->index(['source_type', 'source_id'], 'budget_commitments_source_idx');
        });

        Schema::create('budget_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_cycle_id')->constrained('budget_cycles')->cascadeOnDelete();
            $table->foreignId('budget_allocation_id')->nullable()->constrained('budget_allocations')->cascadeOnDelete();
            $table->foreignId('budget_account_id')->nullable()->constrained('budget_accounts')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_type', 40)->index();
            $table->decimal('amount', 18, 2);
            $table->decimal('signed_amount', 18, 2);
            $table->date('transaction_date')->index();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['mda_id', 'budget_cycle_id', 'transaction_type'], 'budget_transactions_mda_cycle_type_idx');
            $table->index(['budget_allocation_id', 'transaction_type'], 'budget_transactions_allocation_type_idx');
            $table->index(['transaction_type', 'source_type', 'source_id'], 'budget_transactions_type_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_transactions');
        Schema::dropIfExists('budget_commitments');
        Schema::dropIfExists('budget_adjustments');
        Schema::dropIfExists('budget_releases');
        Schema::dropIfExists('budget_allocations');
        Schema::dropIfExists('budget_accounts');
        Schema::dropIfExists('budget_cycles');
    }
};
