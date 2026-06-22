<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StaffAllowanceService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected SalaryCalculationService $salaryCalculationService,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     */
    public function syncAssignments(Staff $staff, array $assignments): void
    {
        DB::transaction(function () use ($staff, $assignments): void {
            $before = $staff->allowanceAssignments()->with('allowanceType')->get()->toArray();
            $typeIds = AllowanceType::query()->forMda((int) $staff->mda_id)->pluck('id')->all();

            foreach ($assignments as $assignment) {
                if (array_key_exists('allowance_code', $assignment)) {
                    throw new InvalidArgumentException('Fixed allowance code payloads are not supported. Use allowance_type_id.');
                }

                $allowanceTypeId = (int) ($assignment['allowance_type_id'] ?? 0);

                if (! in_array($allowanceTypeId, $typeIds, true)) {
                    continue;
                }

                $effectiveFrom = $assignment['effective_from'] ?? now()->toDateString();
                $newEligibility = (bool) ($assignment['is_eligible'] ?? false);
                $source = $assignment['source'] ?? 'staff_management';
                $existing = StaffAllowanceAssignment::query()->firstOrNew([
                    'staff_id' => $staff->id,
                    'allowance_type_id' => $allowanceTypeId,
                    'source' => $source,
                ]);

                if ($existing->exists && (bool) $existing->is_eligible === $newEligibility && $existing->effective_to === null) {
                    continue;
                }

                $existing->fill([
                    'is_eligible' => $newEligibility,
                    'source' => $source,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $assignment['effective_to'] ?? null,
                ])->save();
            }

            $this->recomputeCurrentSalaryPlacement($staff);

            $this->auditLogService->log('staff.allowances.synced', $staff, $before, $staff->allowanceAssignments()->with('allowanceType')->get()->toArray(), [
                'source' => 'staff_management.allowances',
            ]);
        });
    }

    public function effectiveAssignments(Staff $staff): Collection
    {
        return $staff->allowanceAssignments()
            ->with('allowanceType')
            ->where(function ($query): void {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString());
            })
            ->where(function ($query): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString());
            })
            ->orderByRaw("CASE WHEN source = 'staff_management' THEN 1 ELSE 0 END")
            ->orderBy('id')
            ->get()
            ->keyBy('allowance_type_id')
            ->values();
    }

    protected function recomputeCurrentSalaryPlacement(Staff $staff): void
    {
        $placement = $staff->currentSalaryPlacement()->with('salaryScale')->first();

        if (! $placement?->salaryScale) {
            return;
        }

        $eligibleAllowanceCodes = $this->effectiveAssignments($staff)
            ->filter(fn (StaffAllowanceAssignment $assignment): bool => $assignment->is_eligible && $assignment->allowanceType !== null)
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
            return;
        }

        $placement->forceFill([
            'basic_salary' => $calculation['basic_salary'],
            'gross_salary' => $calculation['calculated_gross'],
            'basic_salary_snapshot' => $calculation['basic_salary'],
            'legacy_gross_salary_snapshot' => $calculation['legacy_gross_salary'],
            'calculated_gross_salary_snapshot' => $calculation['calculated_gross'],
            'gross_difference_snapshot' => $calculation['gross_difference'],
        ])->save();
    }
}
