<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cadres') || Schema::hasColumn('cadres', 'department_id')) {
            return;
        }

        Schema::table('cadres', function (Blueprint $table): void {
            $table->foreignId('department_id')->nullable()->after('salary_scale_id')->constrained()->nullOnDelete();
            $table->index('department_id');
        });

        $cadres = DB::table('cadres')
            ->select('id', 'legacy_department_name')
            ->whereNotNull('legacy_department_name')
            ->get();

        foreach ($cadres as $cadre) {
            $matches = DB::table('departments')
                ->whereRaw('LOWER(name) = ?', [strtolower((string) $cadre->legacy_department_name)])
                ->pluck('id');

            if ($matches->count() === 1) {
                DB::table('cadres')
                    ->where('id', $cadre->id)
                    ->update(['department_id' => $matches->first()]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cadres') || ! Schema::hasColumn('cadres', 'department_id')) {
            return;
        }

        Schema::table('cadres', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
