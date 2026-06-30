<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mda_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('year')->index();
            $table->date('opens_at')->nullable();
            $table->date('closes_at')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['mda_id', 'year'], 'promotion_cycles_mda_year_idx');
        });

        Schema::create('promotion_sittings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained('promotion_cycles')->cascadeOnDelete();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('sitting_date');
            $table->text('panel_notes')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('print_authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('print_authorized_at')->nullable();
            $table->timestamps();

            $table->index(['cycle_id', 'mda_id', 'status'], 'promotion_sittings_cycle_mda_status_idx');
        });

        Schema::create('promotion_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained('promotion_cycles')->cascadeOnDelete();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('application_number')->unique();
            $table->string('staff_number')->nullable();
            $table->string('legacy_cno', 50)->nullable();
            $table->string('legacy_psn', 50)->nullable();
            $table->string('surname');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('applicant_remarks')->nullable();
            $table->json('current_snapshot')->nullable();
            $table->foreignId('current_rank_id')->nullable()->constrained('ranks')->nullOnDelete();
            $table->foreignId('current_salary_scale_id')->nullable()->constrained('salary_scales')->nullOnDelete();
            $table->unsignedTinyInteger('current_level')->nullable();
            $table->unsignedTinyInteger('current_step')->nullable();
            $table->foreignId('proposed_rank_id')->nullable()->constrained('ranks')->nullOnDelete();
            $table->foreignId('proposed_salary_scale_id')->nullable()->constrained('salary_scales')->nullOnDelete();
            $table->unsignedTinyInteger('proposed_level')->nullable();
            $table->unsignedTinyInteger('proposed_step')->nullable();
            $table->string('status', 40)->default('submitted')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('screened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('screened_at')->nullable();
            $table->foreignId('sitting_id')->nullable()->constrained('promotion_sittings')->nullOnDelete();
            $table->string('decision', 40)->nullable()->index();
            $table->text('decision_remarks')->nullable();
            $table->text('correction_notes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('letter_printed_at')->nullable();
            $table->timestamps();

            $table->index(['cycle_id', 'mda_id', 'status'], 'promotion_applications_cycle_mda_status_idx');
            $table->index(['mda_id', 'staff_id'], 'promotion_applications_mda_staff_idx');
        });

        Schema::create('promotion_application_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('promotion_applications')->cascadeOnDelete();
            $table->string('document_type', 80);
            $table->string('title');
            $table->string('path')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('promotion_sitting_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sitting_id')->constrained('promotion_sittings')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('promotion_applications')->cascadeOnDelete();
            $table->string('decision', 40)->index();
            $table->text('remarks')->nullable();
            $table->text('correction_notes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['sitting_id', 'application_id'], 'promotion_sitting_application_unique');
        });

        Schema::create('promotion_letters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('promotion_applications')->cascadeOnDelete();
            $table->string('letter_number')->unique();
            $table->date('effective_date');
            $table->string('status', 30)->default('generated')->index();
            $table->string('pdf_path')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->unique('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_letters');
        Schema::dropIfExists('promotion_sitting_decisions');
        Schema::dropIfExists('promotion_sittings');
        Schema::dropIfExists('promotion_application_documents');
        Schema::dropIfExists('promotion_applications');
        Schema::dropIfExists('promotion_cycles');
    }
};
