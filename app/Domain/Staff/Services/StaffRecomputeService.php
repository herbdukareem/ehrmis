<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\Staff;
use App\Services\AuditLogService;

class StaffRecomputeService
{
    public function __construct(
        protected SalaryCalculationService $salaryCalculationService,
        protected StaffAllowanceService $staffAllowanceService,
        protected RetirementPolicyService $retirementPolicyService,
        protected AuditLogService $auditLogService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function recomputeSalary(Staff $staff): array
    {
        $staff->loadMissing(['currentSalaryPlacement.salaryScale', 'allowanceAssignments.allowanceType']);
        $placement = $staff->currentSalaryPlacement;

        if (! $placement?->salaryScale) {
            return [
                'changed' => false,
                'status' => 'missing_current_placement',
            ];
        }

        $eligibleAllowanceCodes = $this->staffAllowanceService->effectiveAssignments($staff)
            ->filter(fn ($assignment): bool => (bool) $assignment->is_eligible && $assignment->allowanceType !== null)
            ->pluck('allowanceType.code')
            ->filter()
            ->values()
            ->all();

        $calculation = $this->salaryCalculationService->calculateGrossForPlacement(
            $placement->salaryScale->code,
            (int) $placement->level,
            (int) $placement->step,
            $eligibleAllowanceCodes,
            (int) $staff->mda_id,
        );

        if ($calculation['basic_salary'] === null || $calculation['calculated_gross'] === null) {
            return [
                'changed' => false,
                'status' => 'missing_salary_rate',
                'placement' => sprintf('%s %s/%s', $placement->salaryScale->code, $placement->level ?? '-', $placement->step ?? '-'),
            ];
        }

        $values = [
            'basic_salary' => $calculation['basic_salary'],
            'gross_salary' => $calculation['calculated_gross'],
            'basic_salary_snapshot' => $calculation['basic_salary'],
            'allowance_total_snapshot' => $calculation['total_allowances'],
            'allowance_breakdown_snapshot' => $calculation['allowance_breakdown'],
            'legacy_gross_salary_snapshot' => $calculation['legacy_gross_salary'],
            'calculated_gross_salary_snapshot' => $calculation['calculated_gross'],
            'gross_difference_snapshot' => $calculation['gross_difference'],
        ];

        $changed = collect($values)->contains(
            fn ($value, string $key): bool => ! $this->placementValueMatches($placement->getAttribute($key), $value)
        );

        if (! $changed) {
            return [
                'changed' => false,
                'status' => 'unchanged',
                'placement' => sprintf('%s %s/%s', $placement->salaryScale->code, $placement->level ?? '-', $placement->step ?? '-'),
                'basic_salary' => $calculation['basic_salary'],
                'total_allowances' => $calculation['total_allowances'],
                'calculated_gross' => $calculation['calculated_gross'],
            ];
        }

        $before = $placement->toArray();
        $placement->forceFill($values)->save();
        $after = $placement->fresh()->toArray();

        $this->auditLogService->log('staff.salary.recomputed', $staff, $before, $after, [
            'source' => 'staff_recompute',
            'allowance_codes' => $eligibleAllowanceCodes,
        ]);

        return [
            'changed' => $changed,
            'status' => 'recomputed',
            'placement' => sprintf('%s %s/%s', $placement->salaryScale->code, $placement->level ?? '-', $placement->step ?? '-'),
            'basic_salary' => $calculation['basic_salary'],
            'total_allowances' => $calculation['total_allowances'],
            'calculated_gross' => $calculation['calculated_gross'],
        ];
    }

    protected function placementValueMatches(mixed $current, mixed $next): bool
    {
        if (is_array($current) || is_array($next)) {
            $current = is_array($current) ? $current : [];
            $next = is_array($next) ? $next : [];

            ksort($current);
            ksort($next);

            return $current === $next;
        }

        if ($current === null || $next === null) {
            return $current === $next;
        }

        return round((float) $current, 2) === round((float) $next, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function recomputeRetirementDate(Staff $staff): array
    {
        $staff->loadMissing('currentEmployment');
        $employment = $staff->currentEmployment;

        if (! $employment) {
            return [
                'changed' => false,
                'status' => 'missing_current_employment',
            ];
        }

        $before = $employment->expected_retirement_date?->toDateString();
        $expectedDate = $this->retirementPolicyService
            ->calculateExpectedRetirementDate($staff->date_of_birth, $employment->date_first_appointment);
        $after = $expectedDate?->toDateString();

        if ($before === $after) {
            return [
                'changed' => false,
                'status' => 'unchanged',
                'expected_retirement_date' => $after,
            ];
        }

        $beforeValues = $employment->toArray();
        $employment->forceFill(['expected_retirement_date' => $after])->save();

        $this->auditLogService->log('staff.retirement_date.recomputed', $staff, $beforeValues, $employment->fresh()->toArray(), [
            'source' => 'staff_recompute',
        ]);

        return [
            'changed' => true,
            'status' => 'recomputed',
            'expected_retirement_date' => $after,
            'previous_retirement_date' => $before,
        ];
    }
}
