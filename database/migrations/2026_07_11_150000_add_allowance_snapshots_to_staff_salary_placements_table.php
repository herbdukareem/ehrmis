<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_salary_placements', function (Blueprint $table): void {
            $table->decimal('allowance_total_snapshot', 15, 2)->nullable()->after('basic_salary_snapshot');
            $table->json('allowance_breakdown_snapshot')->nullable()->after('allowance_total_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('staff_salary_placements', function (Blueprint $table): void {
            $table->dropColumn([
                'allowance_total_snapshot',
                'allowance_breakdown_snapshot',
            ]);
        });
    }
};
