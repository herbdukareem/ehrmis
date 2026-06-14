<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_salary_placements', function (Blueprint $table): void {
            $table->decimal('basic_salary_snapshot', 15, 2)->nullable()->after('gross_salary');
            $table->decimal('legacy_gross_salary_snapshot', 15, 2)->nullable()->after('basic_salary_snapshot');
            $table->decimal('calculated_gross_salary_snapshot', 15, 2)->nullable()->after('legacy_gross_salary_snapshot');
            $table->decimal('gross_difference_snapshot', 15, 2)->nullable()->after('calculated_gross_salary_snapshot');
        });

        DB::table('staff_salary_placements')->update([
            'basic_salary_snapshot' => DB::raw('basic_salary'),
            'calculated_gross_salary_snapshot' => DB::raw('gross_salary'),
        ]);
    }

    public function down(): void
    {
        Schema::table('staff_salary_placements', function (Blueprint $table): void {
            $table->dropColumn([
                'basic_salary_snapshot',
                'legacy_gross_salary_snapshot',
                'calculated_gross_salary_snapshot',
                'gross_difference_snapshot',
            ]);
        });
    }
};
