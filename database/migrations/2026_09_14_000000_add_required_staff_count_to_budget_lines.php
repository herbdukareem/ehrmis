<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table): void {
            $table->unsignedInteger('required_staff_count')->nullable()->after('retiring_count');
        });
    }

    public function down(): void
    {
        Schema::table('budget_lines', function (Blueprint $table): void {
            $table->dropColumn('required_staff_count');
        });
    }
};
