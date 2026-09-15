<?php

namespace App\Console\Commands;

use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\SalaryStructureRateAllowance;
use App\Domain\Staff\Services\AllowanceTypeProvisioningService;
use App\Domain\Staff\Support\UnifiedQualificationCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportSalaryStructureSqlDump extends Command
{
    protected $signature = 'salary-structure:import-sql-dump
        {path : Path to an INSERT INTO staff_salary SQL dump}
        {--scale= : Import only one salary scale code, e.g. CM}
        {--dry-run : Parse and report changes without committing}
        {--overwrite : Update existing allowance rows instead of only creating missing rows}';

    protected $description = 'Import salary structure rates and missing allowance rows from a staff_salary SQL INSERT dump.';

    /**
     * @var array<string, string>
     */
    protected array $allowanceColumnMap = [
        'rural_allowance' => 'rural',
        'teaching_allowance' => 'teaching',
        'CallDoc' => 'call_doctor',
        'CallPharmLab' => 'call_pharm_lab',
        'CallOptOdd' => 'call_opt_odd',
        'CallNurseOthers' => 'call_nurse_others',
        'shift_allowance' => 'shift',
        'specialty_allowance' => 'specialty',
        'hazard_allowance' => 'hazard',
        'domestic_allowance' => 'domestic',
        'entertainment_allowance' => 'entertainment',
        'newspaper_allowance' => 'newspaper',
        'personal_assistant_allowance' => 'personal_assistant',
        'utility_allowance' => 'utility',
        'vehicle_maintenance_allowance' => 'vehicle_maintenance',
    ];

    public function handle(AllowanceTypeProvisioningService $allowanceTypeProvisioningService): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->components->error("SQL dump not found: {$path}");

            return self::FAILURE;
        }

        $scaleFilter = $this->option('scale')
            ? $this->normalizeScaleCode((string) $this->option('scale'))
            : null;
        $dryRun = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');
        $rows = $this->parseRows(file_get_contents($path) ?: '');
        $allowanceTypes = $allowanceTypeProvisioningService
            ->ensureGlobal(array_values($this->allowanceColumnMap))['types']
            ->keyBy('code');
        $summary = [
            'rows_read' => count($rows),
            'rows_imported' => 0,
            'salary_rates_created' => 0,
            'salary_rates_updated' => 0,
            'allowance_rows_created' => 0,
            'allowance_rows_updated' => 0,
            'allowance_rows_existing' => 0,
            'zero_allowances_skipped' => 0,
            'invalid_rows_skipped' => 0,
            'missing_scales_skipped' => 0,
        ];

        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                $scaleCode = $this->normalizeScaleCode($row['scale'] ?? null);

                if ($scaleFilter && $scaleCode !== $scaleFilter) {
                    continue;
                }

                $level = $this->toInteger($row['level'] ?? null);
                $step = $this->toInteger($row['step'] ?? null);
                $basicSalary = $this->toDecimal($row['basic_salary'] ?? null);

                if (! $scaleCode || $level === null || $step === null || $basicSalary === null) {
                    $summary['invalid_rows_skipped']++;
                    continue;
                }

                $salaryScale = $this->salaryScaleFor($scaleCode);

                if (! $salaryScale) {
                    $summary['missing_scales_skipped']++;
                    continue;
                }

                $rate = SalaryStructureRate::query()->firstOrNew([
                    'salary_scale_id' => $salaryScale->id,
                    'level' => $level,
                    'step' => $step,
                ]);
                $rateWasExisting = $rate->exists;
                $rate->fill([
                    'basic_salary' => $basicSalary,
                    'legacy_gross_salary' => $this->toDecimal($row['gross'] ?? null),
                    'status' => ($row['status'] ?? '1') === '1' ? 'active' : 'inactive',
                    'effective_from' => null,
                    'effective_to' => null,
                ])->save();
                $summary[$rateWasExisting ? 'salary_rates_updated' : 'salary_rates_created']++;
                $summary['rows_imported']++;

                foreach ($this->allowanceColumnMap as $legacyColumn => $allowanceCode) {
                    $amount = $this->toDecimal($row[$legacyColumn] ?? null);

                    if ($amount === null || $amount <= 0) {
                        $summary['zero_allowances_skipped']++;
                        continue;
                    }

                    $allowanceType = $allowanceTypes->get($allowanceCode);

                    if (! $allowanceType) {
                        continue;
                    }

                    $rateAllowance = SalaryStructureRateAllowance::query()->firstOrNew([
                        'salary_structure_rate_id' => $rate->id,
                        'allowance_type_id' => $allowanceType->id,
                    ]);

                    if ($rateAllowance->exists && ! $overwrite) {
                        $summary['allowance_rows_existing']++;
                        continue;
                    }

                    $allowanceWasExisting = $rateAllowance->exists;
                    $rateAllowance->fill([
                        'amount' => $amount,
                        'status' => ($row['status'] ?? '1') === '1' ? 'active' : 'inactive',
                    ])->save();
                    $summary[$allowanceWasExisting ? 'allowance_rows_updated' : 'allowance_rows_created']++;
                }
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $throwable) {
            DB::rollBack();
            throw $throwable;
        }

        $this->table(['Metric', 'Value'], collect($summary)->map(fn ($value, $key): array => [
            Str::of($key)->replace('_', ' ')->title()->toString(),
            $value,
        ])->values()->all());

        $this->components->info($dryRun ? 'Dry run complete. No records were committed.' : 'Salary structure SQL dump import complete.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseRows(string $sql): array
    {
        if (! preg_match('/INSERT\s+INTO\s+`?staff_salary`?\s*\((?<columns>.*?)\)\s*VALUES\s*(?<values>.*);?/is', $sql, $match)) {
            return [];
        }

        preg_match_all('/`([^`]+)`/', $match['columns'], $columnMatches);
        $columns = $columnMatches[1] ?? [];

        if ($columns === []) {
            return [];
        }

        preg_match_all('/\((.*?)\)\s*(?:,|;|$)/s', $match['values'], $tupleMatches);

        return collect($tupleMatches[1] ?? [])
            ->map(function (string $tuple) use ($columns): ?array {
                $values = str_getcsv($tuple, ',', "'", '\\');

                if (count($values) !== count($columns)) {
                    return null;
                }

                return collect(array_combine($columns, $values))
                    ->map(fn (mixed $value): mixed => $this->normalizeSqlValue($value))
                    ->all();
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeSqlValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        return str_replace("\\'", "'", $value);
    }

    protected function salaryScaleFor(string $scaleCode): ?SalaryScale
    {
        $salaryScale = SalaryScale::query()->where('code', $scaleCode)->first();

        if ($salaryScale) {
            return $salaryScale;
        }

        $definition = UnifiedQualificationCatalog::salaryScales()[$scaleCode] ?? null;

        if (! $definition) {
            return null;
        }

        return SalaryScale::query()->create([
            'code' => $scaleCode,
            'name' => $definition['name'],
            'min_level' => $definition['min_level'],
            'max_level' => $definition['max_level'],
            'min_step' => $definition['min_step'],
            'max_step' => $definition['max_step'],
            'status' => 'active',
        ]);
    }

    protected function normalizeScaleCode(mixed $value): ?string
    {
        return UnifiedQualificationCatalog::normalizeSalaryScaleCode(is_string($value) ? $value : null);
    }

    protected function toInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    protected function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
