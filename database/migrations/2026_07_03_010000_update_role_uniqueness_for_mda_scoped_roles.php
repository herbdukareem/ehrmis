<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasIndex('roles', 'roles_name_guard_name_unique')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropUnique('roles_name_guard_name_unique');
            });
        }

        if (Schema::hasColumn('roles', 'mda_unique_key')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropColumn('mda_unique_key');
            });
        }

        if (! $this->hasIndex('roles', 'roles_name_guard_scope_mda_unique')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->unique(
                    ['name', 'guard_name', 'scope', 'mda_id'],
                    'roles_name_guard_scope_mda_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('roles', 'roles_name_guard_scope_mda_unique')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropUnique('roles_name_guard_scope_mda_unique');
            });
        }

        if (Schema::hasColumn('roles', 'mda_unique_key')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropColumn('mda_unique_key');
            });
        }

        if (! $this->hasIndex('roles', 'roles_name_guard_name_unique')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
