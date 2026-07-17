<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureIndex('user_access_scopes', ['user_id'], 'user_access_scopes_user_id_idx');
        $this->ensureIndex('user_access_scopes', ['mda_id'], 'user_access_scopes_mda_id_idx');

        Schema::table('user_access_scopes', function (Blueprint $table) {
            $table->dropUnique('user_access_scope_unique');
            $table->foreignId('department_id')->nullable()->after('mda_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'scope_type', 'state_code', 'mda_id', 'department_id'], 'user_access_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_access_scopes', function (Blueprint $table) {
            $table->dropUnique('user_access_scope_unique');
            $table->dropConstrainedForeignId('department_id');
            $table->unique(['user_id', 'scope_type', 'state_code', 'mda_id'], 'user_access_scope_unique');
        });

        $this->dropIndexIfExists('user_access_scopes', 'user_access_scopes_user_id_idx');
        $this->dropIndexIfExists('user_access_scopes', 'user_access_scopes_mda_id_idx');
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
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn (object $index): bool => ($index->name ?? null) === $indexName);
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
