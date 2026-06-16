<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movement_workbooks', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('mda_id');
            $table->unsignedSmallInteger('budget_year')->nullable()->after('year');
            $table->unsignedTinyInteger('budget_minimum_step')->default(5)->after('budget_year');
        });
    }

    public function down(): void
    {
        Schema::table('movement_workbooks', function (Blueprint $table): void {
            $table->dropColumn(['name', 'budget_year', 'budget_minimum_step']);
        });
    }
};
