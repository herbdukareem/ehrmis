<?php

namespace Database\Seeders;

use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\SalaryStructureRateAllowance;
use App\Domain\Staff\Services\AllowanceTypeProvisioningService;
use App\Domain\Staff\Support\UnifiedQualificationCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class November2024SalaryStructureSeeder extends Seeder
{
    protected const EFFECTIVE_FROM = '2024-11-01';

    /**
     * @var array<string, SalaryScale|null>
     */
    protected array $salaryScalesByCode = [];

    public function run(): void
    {
        $allowanceTypes = $this->ensureAllowanceTypes();

        foreach ($this->staffSalaryRows() as $legacyRate) {
            if (($legacyRate->status ?? '1') !== '1') {
                continue;
            }

            $this->seedLegacyRate($legacyRate, $allowanceTypes);
        }
    }

    protected function seedLegacyRate(object $legacyRate, Collection $allowanceTypes): void
    {
        $scaleCode = $this->normalizeScaleCode($legacyRate->scale ?? null);
        $level = $this->toInteger($legacyRate->level ?? null);
        $step = $this->toInteger($legacyRate->step ?? null);
        $gradeCode = $this->normalizeGradeCode($legacyRate->grade_code ?? null);
        $basicSalary = $this->toDecimal($legacyRate->basic_salary ?? null);

        if (! $scaleCode || $level === null || $step === null || $basicSalary === null) {
            return;
        }

        $salaryScale = $this->salaryScaleFor($scaleCode);

        if (! $salaryScale) {
            return;
        }

        $rate = SalaryStructureRate::query()->updateOrCreate(
            [
                'salary_scale_id' => $salaryScale->id,
                'level' => $level,
                'step' => $step,
                'grade_code' => $gradeCode,
            ],
            [
                'detail' => $this->toNullableString($legacyRate->detail ?? null),
                'basic_salary' => $basicSalary,
                'legacy_gross_salary' => $this->toDecimal($legacyRate->gross ?? null),
                'status' => 'active',
                'effective_from' => self::EFFECTIVE_FROM,
                'effective_to' => null,
            ],
        );

        foreach ($this->allowanceColumnMap() as $legacyColumn => $allowanceCode) {
            $amount = $this->toDecimal($legacyRate->{$legacyColumn} ?? null);
            $allowanceType = $allowanceTypes->get($allowanceCode);

            if ($amount === null || $amount <= 0 || ! $allowanceType) {
                continue;
            }

            SalaryStructureRateAllowance::query()->updateOrCreate(
                [
                    'salary_structure_rate_id' => $rate->id,
                    'allowance_type_id' => $allowanceType->id,
                ],
                [
                    'amount' => $amount,
                    'status' => 'active',
                ],
            );
        }
    }

    protected function ensureAllowanceTypes(): Collection
    {
        $codes = array_values($this->allowanceColumnMap());

        return app(AllowanceTypeProvisioningService::class)
            ->ensureGlobal($codes)['types']
            ->keyBy(fn (AllowanceType $type): string => $type->code);
    }

    /**
     * @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    protected function staffSalaryPayload(): array
    {
        $payload = require __DIR__.'/data/salary_structure.php';

        if (! is_array($payload)) {
            return ['columns' => [], 'rows' => []];
        }

        return [
            'columns' => array_values($payload['columns'] ?? []),
            'rows' => array_values($payload['rows'] ?? []),
        ];
    }

    /**
     * @return array<int, object>
     */
    protected function staffSalaryRows(): array
    {
        $payload = $this->staffSalaryPayload();
        $columns = $payload['columns'];

        return collect($payload['rows'])
            ->map(function (array $values) use ($columns): ?object {
                if ($columns === [] || count($values) !== count($columns)) {
                    return null;
                }

                return (object) array_combine($columns, $values);
            })
            ->filter()
            ->values()
            ->all();
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
            'domestic_allowance' => 'domestic',
            'entertainment_allowance' => 'entertainment',
            'newspaper_allowance' => 'newspaper',
            'personal_assistant_allowance' => 'personal_assistant',
            'utility_allowance' => 'utility',
            'vehicle_maintenance_allowance' => 'vehicle_maintenance',
        ];
    }

    protected function salaryScaleFor(string $scaleCode): ?SalaryScale
    {
        if (array_key_exists($scaleCode, $this->salaryScalesByCode)) {
            return $this->salaryScalesByCode[$scaleCode];
        }

        $salaryScale = SalaryScale::query()
            ->where('code', $scaleCode)
            ->first();

        if (! $salaryScale) {
            $definition = UnifiedQualificationCatalog::salaryScales()[$scaleCode] ?? null;

            if ($definition) {
                $salaryScale = SalaryScale::query()->create([
                    'code' => $scaleCode,
                    'name' => $definition['name'],
                    'min_level' => $definition['min_level'],
                    'max_level' => $definition['max_level'],
                    'min_step' => $definition['min_step'],
                    'max_step' => $definition['max_step'],
                    'status' => 'active',
                ]);
            }
        }

        return $this->salaryScalesByCode[$scaleCode] = $salaryScale;
    }

    protected function normalizeScaleCode(mixed $value): ?string
    {
        return UnifiedQualificationCatalog::normalizeSalaryScaleCode(is_string($value) ? $value : null);
    }

    protected function normalizeGradeCode(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    protected function toNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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
