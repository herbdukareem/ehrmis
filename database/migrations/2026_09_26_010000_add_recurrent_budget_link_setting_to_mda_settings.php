<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mda_settings', function (Blueprint $table): void {
            $table->boolean('link_recurrent_budget_to_annual_allocations')
                ->default(false)
                ->after('posting_reference_suffix');
        });
    }

    public function down(): void
    {
        Schema::table('mda_settings', function (Blueprint $table): void {
            $table->dropColumn('link_recurrent_budget_to_annual_allocations');
        });
    }
};
