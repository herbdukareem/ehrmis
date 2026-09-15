<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movement_lines', function (Blueprint $table): void {
            $table->boolean('is_contract_staff')->default(false)->after('retirement_status');
            $table->boolean('is_special_movement')->default(false)->after('is_contract_staff');
        });
    }

    public function down(): void
    {
        Schema::table('movement_lines', function (Blueprint $table): void {
            $table->dropColumn(['is_contract_staff', 'is_special_movement']);
        });
    }
};
