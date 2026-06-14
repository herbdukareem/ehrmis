<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movement_workbooks', function (Blueprint $table): void {
            $table->foreignId('reviewed_by')->nullable()->after('generated_by')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('reviewed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('generated_at');
            $table->timestamp('approved_at')->nullable()->after('reviewed_at');
        });

        Schema::create('movement_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workbook_id')->constrained('movement_workbooks')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salary_scale_id')->nullable()->constrained('salary_scales')->nullOnDelete();
            $table->unsignedTinyInteger('level')->nullable();
            $table->unsignedInteger('staff_count')->default(0);
            $table->unsignedInteger('due_count')->default(0);
            $table->unsignedInteger('retiring_count')->default(0);
            $table->unsignedInteger('retired_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->decimal('current_gross_total', 18, 2)->default(0);
            $table->decimal('proposed_gross_total', 18, 2)->default(0);
            $table->decimal('variance_total', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(
                ['workbook_id', 'department_id', 'salary_scale_id', 'level'],
                'movement_summaries_workbook_department_scale_level_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_summaries');

        Schema::table('movement_workbooks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['reviewed_at', 'approved_at']);
        });
    }
};
