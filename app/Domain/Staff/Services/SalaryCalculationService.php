<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use Illuminate\Support\Str;

class SalaryCalculationService
{
    /** @var array<string, SalaryStructureRate|null> */
    protected array $rateCache = [];

    public function getRate(string $scaleCode, int $level, int $step, ?int $mdaId = null): ?SalaryStructureRate
    {
        $normalizedCode = $this->normalizeScaleCode($scaleCode);

        if (! $normalizedCode) {
            return null;
        }

        $cacheKey = implode('|', [$normalizedCode, $level, $step]);

        if (array_key_exists($cacheKey, $this->rateCache)) {
            return $this->rateCache[$cacheKey];
        }

        $salaryScale = SalaryScale::query()
            ->where('code', $normalizedCode)
            ->first();

        if (! $salaryScale) {
            return $this->rateCache[$cacheKey] = null;
        }

        return $this->rateCache[$cacheKey] = SalaryStructureRate::query()
            ->with(['rateAllowances.allowanceType', 'salaryScale'])
            ->where('salary_scale_id', $salaryScale->id)
            ->where('level', $level)
            ->where('step', $step)
            ->first();
    }

    /**
     * @param  array<int, string>  $eligibleAllowanceCodes
     * @return array{
     *   basic_salary: ?float,
     *   allowance_breakdown: array<string, float>,
     *   total_allowances: float,
     *   calculated_gross: ?float,
     *   legacy_gross_salary: ?float,
     *   gross_difference: ?float
     * }
     */
    public function calculateGrossForPlacement(string $scaleCode, int $level, int $step, array $eligibleAllowanceCodes = [], ?int $mdaId = null): array
    {
        $rate = $this->getRate($scaleCode, $level, $step, $mdaId);

        if (! $rate) {
            return [
                'basic_salary' => null,
                'allowance_breakdown' => [],
                'total_allowances' => 0.0,
                'calculated_gross' => null,
                'legacy_gross_salary' => null,
                'gross_difference' => null,
            ];
        }

        $eligibleCodes = collect($eligibleAllowanceCodes)
            ->map(fn (string $code): string => Str::lower(trim($code)))
            ->filter()
            ->unique()
            ->values();

        $allowanceBreakdown = [];
        $totalAllowances = 0.0;

        foreach ($rate->rateAllowances as $rateAllowance) {
            $allowanceCode = Str::lower((string) optional($rateAllowance->allowanceType)->code);

            if ($allowanceCode === '' || ! $eligibleCodes->contains($allowanceCode)) {
                continue;
            }

            $amount = round((float) $rateAllowance->amount, 2);
            $allowanceBreakdown[$allowanceCode] = $amount;
            $totalAllowances += $amount;
        }

        $basicSalary = round((float) $rate->basic_salary, 2);
        $calculatedGross = round($basicSalary + $totalAllowances, 2);
        $legacyGross = $rate->legacy_gross_salary !== null ? round((float) $rate->legacy_gross_salary, 2) : null;

        return [
            'basic_salary' => $basicSalary,
            'allowance_breakdown' => $allowanceBreakdown,
            'total_allowances' => round($totalAllowances, 2),
            'calculated_gross' => $calculatedGross,
            'legacy_gross_salary' => $legacyGross,
            'gross_difference' => $legacyGross !== null ? round($calculatedGross - $legacyGross, 2) : null,
        ];
    }

    protected function normalizeScaleCode(string $scaleCode): ?string
    {
        $code = Str::upper(preg_replace('/[^A-Z0-9]+/i', '', trim($scaleCode)) ?? '');

        return match ($code) {
            'GRADELEVEL' => 'GL',
            'CONHESS' => 'CH',
            'CONMESS' => 'CM',
            'SPECIALGRADE' => 'SG',
            '' => null,
            default => $code,
        };
    }
}
