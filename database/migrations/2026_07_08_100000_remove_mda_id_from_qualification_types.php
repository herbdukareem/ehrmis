<?php

use App\Domain\Staff\Support\UnifiedQualificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qualification_types')) {
            return;
        }

        $this->collapseToUnifiedCatalog();

        if (Schema::hasColumn('qualification_types', 'mda_id')) {
            Schema::table('qualification_types', function (Blueprint $table): void {
                try {
                    $table->dropForeign(['mda_id']);
                } catch (Throwable) {
                    //
                }
            });

            $this->dropIndexIfExists('qualification_types', 'qualification_types_mda_code_unique', true);

            Schema::table('qualification_types', function (Blueprint $table): void {
                $table->dropColumn('mda_id');
            });
        }

        $this->ensureUniqueIndex('qualification_types', ['code'], 'qualification_types_code_unique');
    }

    public function down(): void
    {
        if (! Schema::hasTable('qualification_types') || Schema::hasColumn('qualification_types', 'mda_id')) {
            return;
        }

        Schema::table('qualification_types', function (Blueprint $table): void {
            $table->foreignId('mda_id')->nullable()->after('id')->constrained('mdas')->nullOnDelete();
        });

        $this->dropIndexIfExists('qualification_types', 'qualification_types_code_unique', true);
        $this->ensureUniqueIndex('qualification_types', ['mda_id', 'code'], 'qualification_types_mda_code_unique');
    }

    protected function collapseToUnifiedCatalog(): void
    {
        $definitions = UnifiedQualificationCatalog::types();
        $allTypes = DB::table('qualification_types')->orderBy('id')->get();
        $groups = [];
        $unmappedIds = [];

        foreach ($allTypes as $type) {
            $canonicalCode = UnifiedQualificationCatalog::canonicalCodeFor($type->code)
                ?? UnifiedQualificationCatalog::canonicalCodeFor($type->name);

            if ($canonicalCode === null || ! isset($definitions[$canonicalCode])) {
                $unmappedIds[] = (int) $type->id;

                continue;
            }

            $groups[$canonicalCode][] = $type;
        }

        foreach ($definitions as $code => $definition) {
            $rows = $groups[$code] ?? [];
            $keeper = collect($rows)
                ->first(fn (object $row): bool => $row->code === $code && ($row->mda_id ?? null) === null)
                ?? collect($rows)->first(fn (object $row): bool => $row->code === $code)
                ?? collect($rows)->first();

            if (! $keeper) {
                $keeperId = DB::table('qualification_types')->insertGetId([
                    'code' => $code,
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $keeperId = (int) $keeper->id;
                DB::table('qualification_types')
                    ->where('id', $keeperId)
                    ->update([
                        'code' => $code,
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'status' => 'active',
                        'updated_at' => now(),
                    ]);
            }

            foreach ($rows as $row) {
                $rowId = (int) $row->id;

                if ($rowId === $keeperId) {
                    continue;
                }

                DB::table('staff_qualifications')
                    ->where('qualification_type_id', $rowId)
                    ->update(['qualification_type_id' => $keeperId]);

                DB::table('qualification_scale_ceilings')
                    ->where('qualification_type_id', $rowId)
                    ->orderBy('id')
                    ->get()
                    ->each(function (object $ceiling) use ($keeperId): void {
                        $exists = DB::table('qualification_scale_ceilings')
                            ->where('qualification_type_id', $keeperId)
                            ->where('salary_scale_id', $ceiling->salary_scale_id)
                            ->exists();

                        if ($exists) {
                            DB::table('qualification_scale_ceilings')->where('id', $ceiling->id)->delete();

                            return;
                        }

                        DB::table('qualification_scale_ceilings')
                            ->where('id', $ceiling->id)
                            ->update(['qualification_type_id' => $keeperId]);
                    });

                DB::table('qualification_types')->where('id', $rowId)->delete();
            }
        }

        if ($unmappedIds !== []) {
            DB::table('staff_qualifications')
                ->whereIn('qualification_type_id', $unmappedIds)
                ->update(['qualification_type_id' => null]);

            DB::table('qualification_scale_ceilings')
                ->whereIn('qualification_type_id', $unmappedIds)
                ->delete();

            DB::table('qualification_types')
                ->whereIn('id', $unmappedIds)
                ->delete();
        }
    }

    protected function ensureUniqueIndex(string $table, array $columns, string $indexName): void
    {
        if ($this->hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->unique($columns, $indexName);
        });
    }

    protected function dropIndexIfExists(string $table, string $indexName, bool $unique = false): void
    {
        if (! $this->hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName, $unique): void {
            $unique ? $blueprint->dropUnique($indexName) : $blueprint->dropIndex($indexName);
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
