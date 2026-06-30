<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_posting_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('request_number')->unique();
            $table->string('posting_type', 40)->index();
            $table->foreignId('from_mda_id')->constrained('mdas')->cascadeOnDelete();
            $table->foreignId('from_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('from_station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->foreignId('to_mda_id')->constrained('mdas')->cascadeOnDelete();
            $table->foreignId('to_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('to_station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->json('staff_snapshot')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('effected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effected_at')->nullable();
            $table->timestamps();

            $table->index(['from_mda_id', 'status'], 'staff_posting_from_mda_status_idx');
            $table->index(['to_mda_id', 'status'], 'staff_posting_to_mda_status_idx');
        });

        Schema::create('staff_posting_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('posting_request_id')->constrained('staff_posting_requests')->cascadeOnDelete();
            $table->string('stage', 50)->index();
            $table->string('decision', 30)->index();
            $table->text('comment')->nullable();
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_posting_letters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('posting_request_id')->constrained('staff_posting_requests')->cascadeOnDelete();
            $table->string('letter_number')->unique();
            $table->string('status', 30)->default('generated')->index();
            $table->string('pdf_path')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->unique('posting_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_posting_letters');
        Schema::dropIfExists('staff_posting_approvals');
        Schema::dropIfExists('staff_posting_requests');
    }
};
