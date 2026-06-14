<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('mda_settings')
            ->join('mdas', 'mdas.id', '=', 'mda_settings.mda_id')
            ->where('mda_settings.domain', 'like', '%.ehrmis-system.test')
            ->select('mda_settings.id', 'mdas.code')
            ->get()
            ->each(function ($setting): void {
                DB::table('mda_settings')
                    ->where('id', $setting->id)
                    ->update(['domain' => strtolower($setting->code).'-ehrmis.test']);
            });
    }

    public function down(): void
    {
        DB::table('mda_settings')
            ->join('mdas', 'mdas.id', '=', 'mda_settings.mda_id')
            ->where('mda_settings.domain', 'like', '%-ehrmis.test')
            ->select('mda_settings.id', 'mdas.code')
            ->get()
            ->each(function ($setting): void {
                DB::table('mda_settings')
                    ->where('id', $setting->id)
                    ->update(['domain' => strtolower($setting->code).'.ehrmis-system.test']);
            });
    }
};
