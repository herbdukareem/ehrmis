<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->boolean('is_contract_staff')->default(false)->after('status');
        });

        if (Schema::hasTable('movement_lines') && Schema::hasColumn('movement_lines', 'is_contract_staff')) {
            DB::table('staff')
                ->whereIn('id', function ($query): void {
                    $query->select('staff_id')
                        ->from('movement_lines')
                        ->where('is_contract_staff', true);
                })
                ->update([
                    'is_contract_staff' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->dropColumn('is_contract_staff');
        });
    }
};
