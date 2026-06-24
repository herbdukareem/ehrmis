<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureIndex('ranks', ['cadre_id'], 'ranks_cadre_id_idx');
        $this->ensureIndex('ranks', ['salary_scale_id'], 'ranks_salary_scale_id_idx');
        $this->swapUniqueIndex(
            'ranks',
            'ranks_cadre_id_name_level_unique',
            ['cadre_id', 'salary_scale_id', 'name', 'level'],
            'ranks_cadre_scale_name_level_unique',
        );
    }

    public function down(): void
    {
        $this->restoreUniqueIndex(
            'ranks',
            'ranks_cadre_scale_name_level_unique',
            ['cadre_id', 'name', 'level'],
            'ranks_cadre_id_name_level_unique',
        );
        $this->dropIndexIfExists('ranks', 'ranks_cadre_id_idx');
        $this->dropIndexIfExists('ranks', 'ranks_salary_scale_id_idx');
    }

    protected function swapUniqueIndex(string $table, string $oldIndex, array $columns, string $newIndex): void
    {
        if ($this->hasIndex($table, $oldIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($oldIndex): void {
                $blueprint->dropUnique($oldIndex);
            });
        }

        if (! $this->hasIndex($table, $newIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $newIndex): void {
                $blueprint->unique($columns, $newIndex);
            });
        }
    }

    protected function restoreUniqueIndex(string $table, string $currentIndex, array $columns, string $restoredIndex): void
    {
        if ($this->hasIndex($table, $currentIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($currentIndex): void {
                $blueprint->dropUnique($currentIndex);
            });
        }

        if (! $this->hasIndex($table, $restoredIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $restoredIndex): void {
                $blueprint->unique($columns, $restoredIndex);
            });
        }
    }

    protected function ensureIndex(string $table, array $columns, string $indexName): void
    {
        if ($this->hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->index($columns, $indexName);
        });
    }

    protected function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropIndex($indexName);
        });
    }

    protected function hasIndex(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(fn ($index) => ($index->name ?? null) === $indexName);
        }

        if ($driver === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $indexName)
                ->exists();
        }

        return false;
    }
};
