<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cadres', function (Blueprint $table): void {
            $table->dropUnique(['name', 'salary_scale_id']);
            $table->unique(['department_id', 'name', 'salary_scale_id'], 'cadres_department_name_scale_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cadres', function (Blueprint $table): void {
            $table->dropUnique('cadres_department_name_scale_unique');
            $table->unique(['name', 'salary_scale_id']);
        });
    }
};
