<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_staff_import_rows', function (Blueprint $table): void {
            $table->foreignId('mda_id')->nullable()->after('legacy_master_staff_id')->constrained('mdas')->nullOnDelete();
            $table->string('staff_number')->nullable()->after('mda_id')->index();
            $table->string('legacy_cno')->nullable()->after('staff_number')->index();
            $table->string('legacy_psn')->nullable()->after('legacy_cno')->index();
            $table->string('legacy_cno_psn')->nullable()->after('legacy_psn')->index();
            $table->string('full_name')->nullable()->after('legacy_cno_psn')->index();
            $table->foreignId('department_id')->nullable()->after('published_staff_id')->constrained('departments')->nullOnDelete();
            $table->string('department_name')->nullable()->after('department_id');
            $table->foreignId('station_id')->nullable()->after('department_name')->constrained('stations')->nullOnDelete();
            $table->string('station_name')->nullable()->after('station_id');
            $table->foreignId('cadre_id')->nullable()->after('station_name')->constrained('cadres')->nullOnDelete();
            $table->string('cadre_name')->nullable()->after('cadre_id');
            $table->foreignId('rank_id')->nullable()->after('cadre_name')->constrained('ranks')->nullOnDelete();
            $table->string('rank_name')->nullable()->after('rank_id');
            $table->foreignId('salary_scale_id')->nullable()->after('rank_name')->constrained('salary_scales')->nullOnDelete();
            $table->string('salary_scale_code')->nullable()->after('salary_scale_id')->index();
            $table->unsignedInteger('level')->nullable()->after('salary_scale_code');
            $table->unsignedInteger('step')->nullable()->after('level');
        });

        Schema::table('legacy_staff_import_errors', function (Blueprint $table): void {
            $table->timestamp('resolved_at')->nullable()->after('severity');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('ignored_at')->nullable()->after('resolved_by');
            $table->foreignId('ignored_by')->nullable()->after('ignored_at')->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable()->after('ignored_by');
            $table->json('resolution_context')->nullable()->after('resolution_notes');
        });
    }

    public function down(): void
    {
        Schema::table('legacy_staff_import_errors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ignored_by');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn([
                'resolved_at',
                'ignored_at',
                'resolution_notes',
                'resolution_context',
            ]);
        });

        Schema::table('legacy_staff_import_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('salary_scale_id');
            $table->dropConstrainedForeignId('rank_id');
            $table->dropConstrainedForeignId('cadre_id');
            $table->dropConstrainedForeignId('station_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('mda_id');
            $table->dropColumn([
                'staff_number',
                'legacy_cno',
                'legacy_psn',
                'legacy_cno_psn',
                'full_name',
                'department_name',
                'station_name',
                'cadre_name',
                'rank_name',
                'salary_scale_code',
                'level',
                'step',
            ]);
        });
    }
};
