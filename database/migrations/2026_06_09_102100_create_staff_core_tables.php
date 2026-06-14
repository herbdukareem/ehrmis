<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->string('staff_number');
            $table->unsignedBigInteger('legacy_staff_id')->nullable();
            $table->unsignedBigInteger('legacy_master_staff_id')->nullable();
            $table->string('legacy_cno', 50)->nullable();
            $table->string('legacy_psn', 50)->nullable();
            $table->string('legacy_cno_psn', 120)->nullable();
            $table->string('surname');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('full_name');
            $table->string('sex', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index('mda_id');
            $table->index('legacy_cno');
            $table->index('legacy_psn');
            $table->index('legacy_cno_psn');
            $table->unique(['mda_id', 'staff_number'], 'staff_mda_staff_number_unique');
        });

        Schema::create('staff_personal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('lga')->nullable();
            $table->string('state_of_origin')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('file_no')->nullable();
            $table->timestamps();

            $table->unique('staff_id');
        });

        Schema::create('staff_employments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('mda_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('station_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_name')->nullable();
            $table->foreignId('cadre_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rank_id')->nullable()->constrained()->nullOnDelete();
            $table->string('staff_category')->nullable();
            $table->string('initial_rank')->nullable();
            $table->date('date_first_appointment')->nullable();
            $table->date('date_last_promotion')->nullable();
            $table->date('expected_retirement_date')->nullable();
            $table->date('next_promotion_date')->nullable();
            $table->string('employment_status', 30)->default('active')->index();
            $table->boolean('is_current')->default(true)->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'is_current'], 'staff_employment_current_idx');
            $table->index('mda_id');
            $table->index('department_id');
            $table->index('cadre_id');
            $table->index('rank_id');
        });

        Schema::create('staff_salary_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('salary_scale_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('level')->nullable();
            $table->unsignedTinyInteger('step')->nullable();
            $table->decimal('basic_salary', 15, 2)->nullable();
            $table->decimal('gross_salary', 15, 2)->nullable();
            $table->string('source', 50)->default('legacy_snapshot');
            $table->boolean('is_current')->default(true)->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'is_current'], 'staff_salary_current_idx');
            $table->index(['salary_scale_id', 'level', 'step'], 'staff_salary_scale_level_step_idx');
        });

        Schema::create('staff_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('qualification_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('qualification_name')->nullable();
            $table->string('highest_qualification_name')->nullable();
            $table->string('specialization')->nullable();
            $table->boolean('is_highest')->default(false)->index();
            $table->string('source', 50)->default('legacy_import');
            $table->timestamps();
        });

        Schema::create('staff_allowance_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('allowance_type_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_eligible')->default(false);
            $table->string('source', 50)->default('legacy_import');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'allowance_type_id'], 'staff_allowance_staff_type_idx');
            $table->unique(['staff_id', 'allowance_type_id', 'source'], 'staff_allowance_staff_type_source_unique');
        });

        Schema::create('staff_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('status', 30)->index();
            $table->string('reason')->nullable();
            $table->date('effective_from')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_status_histories');
        Schema::dropIfExists('staff_allowance_assignments');
        Schema::dropIfExists('staff_qualifications');
        Schema::dropIfExists('staff_salary_placements');
        Schema::dropIfExists('staff_employments');
        Schema::dropIfExists('staff_personal_details');
        Schema::dropIfExists('staff');
    }
};
