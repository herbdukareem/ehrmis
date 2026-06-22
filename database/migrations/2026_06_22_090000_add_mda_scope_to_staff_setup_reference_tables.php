<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addNullableMdaScope('allowance_types');
        $this->swapUniqueIndex(
            'allowance_types',
            'allowance_types_code_unique',
            ['mda_id', 'code'],
            'allowance_types_mda_code_unique',
        );

        $this->addNullableMdaScope('salary_scales');
        $this->swapUniqueIndex(
            'salary_scales',
            'salary_scales_code_unique',
            ['mda_id', 'code'],
            'salary_scales_mda_code_unique',
        );

        $this->addNullableMdaScope('qualification_types');
        $this->swapUniqueIndex(
            'qualification_types',
            'qualification_types_code_unique',
            ['mda_id', 'code'],
            'qualification_types_mda_code_unique',
        );

        $this->addNullableMdaScope('salary_structure_rates');
        $this->ensureIndex('salary_structure_rates', ['salary_scale_id'], 'salary_structure_rates_salary_scale_id_idx');
        $this->swapUniqueIndex(
            'salary_structure_rates',
            'salary_structure_scale_level_step_unique',
            ['mda_id', 'salary_scale_id', 'level', 'step'],
            'salary_structure_rates_mda_scale_level_step_unique',
        );

        $this->addNullableMdaScope('salary_structure_rate_allowances');
        $this->ensureIndex(
            'salary_structure_rate_allowances',
            ['salary_structure_rate_id'],
            'ssra_salary_structure_rate_id_idx',
        );
        $this->swapUniqueIndex(
            'salary_structure_rate_allowances',
            'salary_structure_rate_allowance_unique',
            ['mda_id', 'salary_structure_rate_id', 'allowance_type_id'],
            'salary_structure_rate_allowances_mda_rate_allowance_unique',
        );

        $this->backfillSalaryScaleMdas();
        $this->backfillSalaryStructureMdas();
    }

    public function down(): void
    {
        $this->restoreUniqueIndex(
            'salary_structure_rate_allowances',
            'salary_structure_rate_allowances_mda_rate_allowance_unique',
            ['salary_structure_rate_id', 'allowance_type_id'],
            'salary_structure_rate_allowance_unique',
        );
        $this->dropNullableMdaScope('salary_structure_rate_allowances');

        $this->restoreUniqueIndex(
            'salary_structure_rates',
            'salary_structure_rates_mda_scale_level_step_unique',
            ['salary_scale_id', 'level', 'step'],
            'salary_structure_scale_level_step_unique',
        );
        $this->dropNullableMdaScope('salary_structure_rates');

        $this->restoreUniqueIndex(
            'qualification_types',
            'qualification_types_mda_code_unique',
            ['code'],
            'qualification_types_code_unique',
        );
        $this->dropNullableMdaScope('qualification_types');

        $this->restoreUniqueIndex(
            'salary_scales',
            'salary_scales_mda_code_unique',
            ['code'],
            'salary_scales_code_unique',
        );
        $this->dropNullableMdaScope('salary_scales');

        $this->restoreUniqueIndex(
            'allowance_types',
            'allowance_types_mda_code_unique',
            ['code'],
            'allowance_types_code_unique',
        );
        $this->dropNullableMdaScope('allowance_types');

        $this->dropIndexIfExists('salary_structure_rates', 'salary_structure_rates_salary_scale_id_idx');
        $this->dropIndexIfExists('salary_structure_rate_allowances', 'ssra_salary_structure_rate_id_idx');
    }

    protected function backfillSalaryScaleMdas(): void
    {
        $candidates = DB::table('salary_scales')
            ->leftJoin('cadres', 'cadres.salary_scale_id', '=', 'salary_scales.id')
            ->leftJoin('departments', 'departments.id', '=', 'cadres.department_id')
            ->whereNull('salary_scales.mda_id')
            ->select('salary_scales.id')
            ->selectRaw('MIN(departments.mda_id) as inferred_mda_id')
            ->selectRaw('COUNT(DISTINCT departments.mda_id) as distinct_mda_count')
            ->groupBy('salary_scales.id')
            ->get();

        foreach ($candidates as $candidate) {
            if ((int) $candidate->distinct_mda_count === 1 && $candidate->inferred_mda_id !== null) {
                DB::table('salary_scales')
                    ->where('id', $candidate->id)
                    ->update(['mda_id' => (int) $candidate->inferred_mda_id]);
            }
        }
    }

    protected function backfillSalaryStructureMdas(): void
    {
        $rates = DB::table('salary_structure_rates')
            ->select('id', 'salary_scale_id')
            ->whereNull('mda_id')
            ->get();

        foreach ($rates as $rate) {
            $mdaId = DB::table('salary_scales')
                ->where('id', $rate->salary_scale_id)
                ->value('mda_id');

            if ($mdaId !== null) {
                DB::table('salary_structure_rates')
                    ->where('id', $rate->id)
                    ->update(['mda_id' => (int) $mdaId]);
            }
        }

        $allowances = DB::table('salary_structure_rate_allowances')
            ->select('id', 'salary_structure_rate_id')
            ->whereNull('mda_id')
            ->get();

        foreach ($allowances as $allowance) {
            $mdaId = DB::table('salary_structure_rates')
                ->where('id', $allowance->salary_structure_rate_id)
                ->value('mda_id');

            if ($mdaId !== null) {
                DB::table('salary_structure_rate_allowances')
                    ->where('id', $allowance->id)
                    ->update(['mda_id' => (int) $mdaId]);
            }
        }
    }

    protected function addNullableMdaScope(string $table): void
    {
        if (Schema::hasColumn($table, 'mda_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->foreignId('mda_id')->nullable()->after('id')->constrained('mdas')->nullOnDelete();
        });
    }

    protected function dropNullableMdaScope(string $table): void
    {
        if (! Schema::hasColumn($table, 'mda_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropConstrainedForeignId('mda_id');
        });
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
