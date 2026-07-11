<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->collapseAllowanceTypes();
        $this->collapseSalaryScales();
        $this->collapseSalaryStructureRates();
        $this->collapseSalaryStructureRateAllowances();

        $this->dropMdaScope('salary_structure_rate_allowances', 'salary_structure_rate_allowances_mda_rate_allowance_unique');
        $this->dropMdaScope('salary_structure_rates', 'salary_structure_rates_mda_scale_level_step_unique');
        $this->dropMdaScope('salary_scales', 'salary_scales_mda_code_unique');
        $this->dropMdaScope('allowance_types', 'allowance_types_mda_code_unique');

        $this->ensureUniqueIndex('salary_structure_rate_allowances', ['salary_structure_rate_id', 'allowance_type_id'], 'salary_structure_rate_allowance_unique');
        if (Schema::hasColumn('salary_structure_rates', 'grade_code')) {
            $this->dropIndexIfExists('salary_structure_rates', 'salary_structure_scale_level_step_unique', true);
            $this->ensureUniqueIndex('salary_structure_rates', ['salary_scale_id', 'level', 'step', 'grade_code'], 'salary_structure_rate_identity_unique');
        } else {
            $this->ensureUniqueIndex('salary_structure_rates', ['salary_scale_id', 'level', 'step'], 'salary_structure_scale_level_step_unique');
        }
        $this->ensureUniqueIndex('salary_scales', ['code'], 'salary_scales_code_unique');
        $this->ensureUniqueIndex('allowance_types', ['code'], 'allowance_types_code_unique');
    }

    public function down(): void
    {
        $this->addNullableMdaScope('allowance_types');
        $this->addNullableMdaScope('salary_scales');
        $this->addNullableMdaScope('salary_structure_rates');
        $this->addNullableMdaScope('salary_structure_rate_allowances');
    }

    protected function collapseAllowanceTypes(): void
    {
        if (! Schema::hasTable('allowance_types')) {
            return;
        }

        $this->collapseByColumns('allowance_types', ['code'], [
            ['table' => 'staff_allowance_assignments', 'column' => 'allowance_type_id'],
            ['table' => 'salary_structure_rate_allowances', 'column' => 'allowance_type_id'],
        ]);
    }

    protected function collapseSalaryScales(): void
    {
        if (! Schema::hasTable('salary_scales')) {
            return;
        }

        $this->collapseByColumns('salary_scales', ['code'], [
            ['table' => 'cadres', 'column' => 'salary_scale_id', 'unique' => ['name', 'salary_scale_id']],
            ['table' => 'ranks', 'column' => 'salary_scale_id'],
            ['table' => 'qualification_scale_ceilings', 'column' => 'salary_scale_id', 'unique' => ['qualification_type_id', 'salary_scale_id']],
            ['table' => 'promotion_policies', 'column' => 'salary_scale_id', 'unique' => ['salary_scale_id', 'min_level', 'max_level', 'policy_type']],
            ['table' => 'staff_salary_placements', 'column' => 'salary_scale_id'],
            ['table' => 'legacy_staff_import_rows', 'column' => 'salary_scale_id'],
            ['table' => 'movement_lines', 'column' => 'current_salary_scale_id'],
            ['table' => 'movement_lines', 'column' => 'proposed_salary_scale_id'],
            ['table' => 'movement_summaries', 'column' => 'salary_scale_id'],
            ['table' => 'budget_lines', 'column' => 'salary_scale_id'],
            ['table' => 'promotion_applications', 'column' => 'current_salary_scale_id'],
            ['table' => 'promotion_applications', 'column' => 'proposed_salary_scale_id'],
            [
                'table' => 'salary_structure_rates',
                'column' => 'salary_scale_id',
                'unique' => Schema::hasColumn('salary_structure_rates', 'grade_code')
                    ? ['salary_scale_id', 'level', 'step', 'grade_code']
                    : ['salary_scale_id', 'level', 'step'],
            ],
        ]);
    }

    protected function collapseSalaryStructureRates(): void
    {
        if (! Schema::hasTable('salary_structure_rates')) {
            return;
        }

        $columns = Schema::hasColumn('salary_structure_rates', 'grade_code')
            ? ['salary_scale_id', 'level', 'step', 'grade_code']
            : ['salary_scale_id', 'level', 'step'];

        $this->collapseByColumns('salary_structure_rates', $columns, [
            ['table' => 'salary_structure_rate_allowances', 'column' => 'salary_structure_rate_id'],
        ]);
    }

    protected function collapseSalaryStructureRateAllowances(): void
    {
        if (! Schema::hasTable('salary_structure_rate_allowances')) {
            return;
        }

        $this->collapseByColumns('salary_structure_rate_allowances', ['salary_structure_rate_id', 'allowance_type_id'], []);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array{table: string, column: string, unique?: list<string>}>  $references
     */
    protected function collapseByColumns(string $table, array $columns, array $references): void
    {
        $groups = DB::table($table)
            ->select($columns)
            ->selectRaw('MIN(id) as keeper_id')
            ->selectRaw('COUNT(*) as row_count')
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $keeperId = (int) $group->keeper_id;
            $duplicateIds = DB::table($table)
                ->where(fn ($query) => $this->whereGroupColumns($query, $columns, $group))
                ->where('id', '!=', $keeperId)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($duplicateIds === []) {
                continue;
            }

            foreach ($references as $reference) {
                if (! Schema::hasTable($reference['table']) || ! Schema::hasColumn($reference['table'], $reference['column'])) {
                    continue;
                }

                if (($reference['unique'] ?? []) !== []) {
                    $this->remapUniqueReference(
                        $reference['table'],
                        $reference['column'],
                        $reference['unique'],
                        $keeperId,
                        $duplicateIds,
                    );

                    continue;
                }

                DB::table($reference['table'])
                    ->whereIn($reference['column'], $duplicateIds)
                    ->update([$reference['column'] => $keeperId]);
            }

            DB::table($table)->whereIn('id', $duplicateIds)->delete();
        }
    }

    /**
     * @param  list<string>  $uniqueColumns
     * @param  list<int>  $duplicateIds
     */
    protected function remapUniqueReference(string $table, string $foreignColumn, array $uniqueColumns, int $keeperId, array $duplicateIds): void
    {
        $rows = DB::table($table)
            ->whereIn($foreignColumn, $duplicateIds)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $exists = DB::table($table)
                ->where('id', '!=', $row->id)
                ->where(function ($query) use ($row, $foreignColumn, $uniqueColumns, $keeperId): void {
                    foreach ($uniqueColumns as $column) {
                        $value = $column === $foreignColumn ? $keeperId : $row->{$column};
                        $query->where($column, $value);
                    }
                })
                ->exists();

            if ($exists) {
                DB::table($table)->where('id', $row->id)->delete();

                continue;
            }

            DB::table($table)
                ->where('id', $row->id)
                ->update([$foreignColumn => $keeperId]);
        }
    }

    protected function whereGroupColumns($query, array $columns, object $group): void
    {
        foreach ($columns as $column) {
            $query->where($column, $group->{$column});
        }
    }

    protected function dropMdaScope(string $table, string $mdaUniqueIndex): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $this->dropIndexIfExists($table, $mdaUniqueIndex, true);

        if (! Schema::hasColumn($table, 'mda_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            try {
                $blueprint->dropForeign(['mda_id']);
            } catch (Throwable) {
                //
            }
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('mda_id');
        });
    }

    protected function addNullableMdaScope(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'mda_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->foreignId('mda_id')->nullable()->after('id')->constrained('mdas')->nullOnDelete();
        });
    }

    protected function ensureUniqueIndex(string $table, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($table) || $this->hasIndex($table, $indexName)) {
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
