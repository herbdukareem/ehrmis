<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_mda_id')->nullable()->constrained('mdas')->nullOnDelete();
            $table->foreignId('module_id')->nullable()->constrained('modules')->nullOnDelete();
            $table->string('module_code')->default('service_reporting');
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->string('frequency', 20)->default('monthly');
            $table->string('status', 20)->default('draft')->index();
            $table->boolean('requires_approval')->default(true);
            $table->unsignedTinyInteger('submission_deadline_day')->nullable();
            $table->boolean('allow_late_submission')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_mda_id', 'status']);
        });

        Schema::create('report_template_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_template_id')->constrained('report_templates')->cascadeOnDelete();
            $table->string('title');
            $table->string('code');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['report_template_id', 'code']);
        });

        Schema::create('report_template_indicators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_template_section_id')->constrained('report_template_sections')->cascadeOnDelete();
            $table->string('code');
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('value_type', 20)->default('integer');
            $table->string('unit')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_computed')->default(false);
            $table->json('compute_formula')->nullable();
            $table->json('validation_rules')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->unique(['report_template_section_id', 'code'], 'report_template_indicator_section_code_unique');
            $table->index('code');
        });

        Schema::create('report_template_dimensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_template_indicator_id')->constrained('report_template_indicators')->cascadeOnDelete();
            $table->string('dimension_key');
            $table->string('dimension_label');
            $table->json('dimension_values');
            $table->boolean('is_required')->default(false);
            $table->string('total_strategy', 20)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('report_template_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_template_id')->constrained('report_templates')->cascadeOnDelete();
            $table->foreignId('mda_id')->constrained('mdas')->cascadeOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('facility_type')->nullable();
            $table->date('required_from')->nullable();
            $table->date('required_until')->nullable();
            $table->boolean('is_required')->default(true);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->index(['mda_id', 'status']);
        });

        Schema::create('reporting_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('frequency', 20);
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->unsignedTinyInteger('period_quarter')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->date('submission_due_date')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->timestamps();

            $table->unique(['frequency', 'period_year', 'period_month', 'period_quarter'], 'reporting_period_unique');
        });

        Schema::create('report_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_template_id')->constrained('report_templates')->restrictOnDelete();
            $table->foreignId('reporting_period_id')->constrained('reporting_periods')->restrictOnDelete();
            $table->foreignId('mda_id')->constrained('mdas')->restrictOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->text('return_reason')->nullable();
            $table->json('summary')->nullable();
            $table->boolean('is_late')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['report_template_id', 'reporting_period_id', 'mda_id', 'station_id'],
                'report_submission_template_period_mda_station_unique'
            );
            $table->index(['mda_id', 'status']);
        });

        Schema::create('report_submission_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_submission_id')->constrained('report_submissions')->cascadeOnDelete();
            $table->foreignId('report_template_indicator_id')->constrained('report_template_indicators')->restrictOnDelete();
            $table->string('indicator_code');
            $table->string('dimension_key')->nullable();
            $table->string('dimension_value')->nullable();
            $table->bigInteger('value_integer')->nullable();
            $table->decimal('value_decimal', 18, 4)->nullable();
            $table->text('value_text')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->decimal('computed_value_decimal', 18, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['indicator_code', 'dimension_key', 'dimension_value'], 'report_values_indicator_dimension_index');
        });

        Schema::create('report_submission_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_submission_id')->constrained('report_submissions')->cascadeOnDelete();
            $table->string('action', 30);
            $table->text('comment')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acted_at');
            $table->string('before_status', 20)->nullable();
            $table->string('after_status', 20);
            $table->timestamps();
        });

        Schema::create('report_submission_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_submission_id')->constrained('report_submissions')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('file_path');
            $table->string('file_mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_submission_attachments');
        Schema::dropIfExists('report_submission_reviews');
        Schema::dropIfExists('report_submission_values');
        Schema::dropIfExists('report_submissions');
        Schema::dropIfExists('reporting_periods');
        Schema::dropIfExists('report_template_assignments');
        Schema::dropIfExists('report_template_dimensions');
        Schema::dropIfExists('report_template_indicators');
        Schema::dropIfExists('report_template_sections');
        Schema::dropIfExists('report_templates');
    }
};
