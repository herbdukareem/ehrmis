<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stations') || ! Schema::hasColumn('stations', 'department_id')) {
            return;
        }

        Schema::table('stations', function (Blueprint $table) {
            try {
                $table->dropForeign(['department_id']);
            } catch (\Throwable) {
                // Ignore environments where the foreign key was never created or already removed.
            }

            try {
                $table->dropIndex('stations_mda_id_department_id_index');
            } catch (\Throwable) {
                // Ignore environments where the composite index does not exist.
            }

            $table->dropColumn('department_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stations') || Schema::hasColumn('stations', 'department_id')) {
            return;
        }

        Schema::table('stations', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('mda_id')->constrained()->cascadeOnDelete();
            $table->index(['mda_id', 'department_id']);
        });
    }
};
