<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'station_id')) {
                $table->foreignId('station_id')
                    ->nullable()
                    ->after('mda_id')
                    ->constrained('stations')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'station_id')) {
                $table->dropConstrainedForeignId('station_id');
            }
        });
    }
};
