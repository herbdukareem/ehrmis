<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\SalaryStructureRateAllowance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LegacySalaryStructureImportService
{
    /**
     * @var array<string, \App\Domain\Staff\Models\SalaryScale>
     */
    protected array $salaryScaleByCode = [];

    /**
     * @var array<string, \App\Domain\Staff\Models\AllowanceType>
     */
    protected array $allowanceTypesByCode = [];

    /**
     * @param  array{dry_run?: bool, active_only?: bool}  $options
     * @return array<string, mixed>
     */
    public function import(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $activeOnly = array_key_exists('active_only', $options) ? (bool) $options['active_only'] : true;

        $summary = [
            'dry_run' => $dryRun,
            'active_only' => $activeOnly,
            'salary_rates_created' => 0,
            'salary_rates_updated' => 0,
            'allowance_types_created' => 0,
            'allowance_types_updated' => 0,
            'salary_allowance_rows_created' => 0,
            'salary_allowance_rows_updated' => 0,
            'skipped_zero_allowance_amounts' => 0,
            'skipped_missing_salary_scales' => 0,
            'invalid_rows_skipped' => 0,
            'rows_read' => 0,
            'warnings' => [],
            'errors' => [],
        ];

        $this->loadSalaryScaleLookup();

        DB::beginTransaction();

        try {
            $this->seedAllowanceTypes($summary);

            $legacyRates = DB::connection('legacy')
                ->table('staff_salary')
                ->when($activeOnly, fn ($query) => $query->where('status', '1'))
                ->orderBy('scale')
                ->orderBy('level')
                ->orderBy('step')
                ->get();

            $summary['rows_read'] = $legacyRates->count();

            foreach ($legacyRates as $legacyRate) {
                $scaleCode = $this->normalizeScaleCode($legacyRate->scale ?? null);
                $level = $this->toInteger($legacyRate->level ?? null);
                $step = $this->toInteger($legacyRate->step ?? null);
                $basicSalary = $this->toDecimal($legacyRate->basic_salary ?? null);

                if (! $scaleCode || $level === null || $step === null || $basicSalary === null) {
                    $summary['invalid_rows_skipped']++;
                    $summary['warnings'][] = 'Skipped invalid staff_salary row for scale `'.($legacyRate->scale ?? 'unknown').'`, level `'.($legacyRate->level ?? 'null').'`, step `'.($legacyRate->step ?? 'null').'`.';

                    continue;
                }

                $salaryScale = $this->salaryScaleByCode[$scaleCode] ?? null;

                if (! $salaryScale) {
                    $summary['skipped_missing_salary_scales']++;
                    $summary['warnings'][] = 'Skipped salary structure row for scale `'.$scaleCode.'` because the salary scale was not found in the new system.';

                    continue;
                }

                $rate = SalaryStructureRate::query()->firstOrNew([
                    'salary_scale_id' => $salaryScale->id,
                    'level' => $level,
                    'step' => $step,
                ]);

                $wasRecentlyCreated = ! $rate->exists;
                $rate->fill([
                    'basic_salary' => $basicSalary,
                    'legacy_gross_salary' => $this->toDecimal($legacyRate->gross ?? null),
                    'status' => ($legacyRate->status ?? '1') === '1' ? 'active' : 'inactive',
                    'effective_from' => null,
                    'effective_to' => null,
                ]);
                $rate->save();

                $summary[$wasRecentlyCreated ? 'salary_rates_created' : 'salary_rates_updated']++;

                foreach ($this->allowanceColumnMap() as $legacyColumn => $allowanceCode) {
                    $amount = $this->toDecimal($legacyRate->{$legacyColumn} ?? null);

                    if ($amount === null || $amount <= 0) {
                        $summary['skipped_zero_allowance_amounts']++;
                        continue;
                    }

                    $allowanceType = $this->allowanceTypesByCode[$allowanceCode] ?? null;

                    if (! $allowanceType) {
                        $summary['errors'][] = 'Allowance type `'.$allowanceCode.'` was not available during salary structure import.';
                        continue;
                    }

                    $rateAllowance = SalaryStructureRateAllowance::query()->firstOrNew([
                        'salary_structure_rate_id' => $rate->id,
                        'allowance_type_id' => $allowanceType->id,
                    ]);

                    $allowanceWasRecentlyCreated = ! $rateAllowance->exists;
                    $rateAllowance->fill([
                        'amount' => $amount,
                        'status' => ($legacyRate->status ?? '1') === '1' ? 'active' : 'inactive',
                    ]);
                    $rateAllowance->save();

                    $summary[$allowanceWasRecentlyCreated ? 'salary_allowance_rows_created' : 'salary_allowance_rows_updated']++;
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $throwable) {
            DB::rollBack();
            $summary['errors'][] = $throwable->getMessage();
            throw $throwable;
        }

        return $summary;
    }

    protected function loadSalaryScaleLookup(): void
    {
        $this->salaryScaleByCode = SalaryScale::query()
            ->get()
            ->keyBy(fn (SalaryScale $salaryScale): string => Str::upper($salaryScale->code))
            ->all();
    }

    protected function seedAllowanceTypes(array &$summary): void
    {
        foreach ($this->allowanceTypeDefinitions() as $definition) {
            $allowanceType = AllowanceType::query()->firstOrNew(['code' => $definition['code']]);
            $wasRecentlyCreated = ! $allowanceType->exists;
            $allowanceType->fill([
                'name' => $definition['name'],
                'description' => $definition['description'],
                'status' => 'active',
            ]);
            $allowanceType->save();

            $summary[$wasRecentlyCreated ? 'allowance_types_created' : 'allowance_types_updated']++;
            $this->allowanceTypesByCode[$allowanceType->code] = $allowanceType;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function allowanceColumnMap(): array
    {
        return [
            'rural_allowance' => 'rural',
            'teaching_allowance' => 'teaching',
            'CallDoc' => 'call_doctor',
            'CallPharmLab' => 'call_pharm_lab',
            'CallOptOdd' => 'call_opt_odd',
            'CallNurseOthers' => 'call_nurse_others',
            'shift_allowance' => 'shift',
            'specialty_allowance' => 'specialty',
            'hazard_allowance' => 'hazard',
        ];
    }

    /**
     * @return array<int, array{code: string, name: string, description: string}>
     */
    protected function allowanceTypeDefinitions(): array
    {
        return [
            ['code' => 'rural', 'name' => 'Rural Allowance', 'description' => 'Imported from legacy staff_salary.rural_allowance.'],
            ['code' => 'teaching', 'name' => 'Teaching Allowance', 'description' => 'Imported from legacy staff_salary.teaching_allowance.'],
            ['code' => 'call_doctor', 'name' => 'Call Allowance - Doctor', 'description' => 'Imported from legacy staff_salary.CallDoc.'],
            ['code' => 'call_pharm_lab', 'name' => 'Call Allowance - Pharmacy/Lab', 'description' => 'Imported from legacy staff_salary.CallPharmLab.'],
            ['code' => 'call_opt_odd', 'name' => 'Call Allowance - Optometry/ODD', 'description' => 'Imported from legacy staff_salary.CallOptOdd.'],
            ['code' => 'call_nurse_others', 'name' => 'Call Allowance - Nurse/Others', 'description' => 'Imported from legacy staff_salary.CallNurseOthers.'],
            ['code' => 'shift', 'name' => 'Shift Allowance', 'description' => 'Imported from legacy staff_salary.shift_allowance.'],
            ['code' => 'specialty', 'name' => 'Specialty Allowance', 'description' => 'Imported from legacy staff_salary.specialty_allowance.'],
            ['code' => 'hazard', 'name' => 'Hazard Allowance', 'description' => 'Imported from legacy staff_salary.hazard_allowance.'],
            ['code' => 'domestic', 'name' => 'Domestic Allowance', 'description' => 'Reserved for future allowance configuration.'],
            ['code' => 'professional', 'name' => 'Professional Allowance', 'description' => 'Reserved for future allowance configuration.'],
            ['code' => 'responsibility', 'name' => 'Responsibility Allowance', 'description' => 'Reserved for future allowance configuration.'],
            ['code' => 'other', 'name' => 'Other Allowance', 'description' => 'Reserved for future allowance configuration.'],
        ];
    }

    protected function normalizeScaleCode(mixed $value): ?string
    {
        $string = is_string($value) ? trim($value) : null;

        if ($string === null || $string === '') {
            return null;
        }

        $code = Str::upper(preg_replace('/[^A-Z0-9]+/i', '', $string) ?? '');

        return match ($code) {
            'GRADELEVEL' => 'GL',
            'CONHESS' => 'CH',
            'CONMESS' => 'CM',
            'SPECIALGRADE' => 'SG',
            '' => null,
            default => $code,
        };
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
