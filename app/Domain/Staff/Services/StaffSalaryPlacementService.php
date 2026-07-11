<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

class StaffSalaryPlacementService
{
    public function __construct(
        protected SalaryCalculationService $salaryCalculationService,
        protected AuditLogService $auditLogService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $placementData
     */
    public function createPlacement(Staff $staff, array $placementData): StaffSalaryPlacement
    {
        return DB::transaction(function () use ($staff, $placementData): StaffSalaryPlacement {
            $currentPlacement = $staff->currentSalaryPlacement()->with('salaryScale')->first();
            $salaryScale = $placementData['salary_scale'];
            $eligibleAllowanceCodes = $staff->allowanceAssignments()
                ->with('allowanceType')
                ->where('is_eligible', true)
                ->where(function ($query): void {
                    $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString());
                })
                ->get()
                ->pluck('allowanceType.code')
                ->filter()
                ->values()
                ->all();

            $salaryBreakdown = $this->salaryCalculationService->calculateGrossForPlacement(
                (string) $salaryScale->code,
                (int) $placementData['level'],
                (int) $placementData['step'],
                $eligibleAllowanceCodes,
                (int) $staff->mda_id,
            );

            if ($currentPlacement) {
                $currentPlacement->forceFill([
                    'is_current' => false,
                    'effective_to' => $placementData['effective_from'] ?? now()->toDateString(),
                ])->save();
            }

            $placement = StaffSalaryPlacement::query()->create([
                'staff_id' => $staff->id,
                'salary_scale_id' => $salaryScale->id,
                'level' => $placementData['level'],
                'step' => $placementData['step'],
                'basic_salary' => $salaryBreakdown['basic_salary'],
                'gross_salary' => $salaryBreakdown['calculated_gross'],
                'basic_salary_snapshot' => $salaryBreakdown['basic_salary'],
                'allowance_total_snapshot' => $salaryBreakdown['total_allowances'],
                'allowance_breakdown_snapshot' => $salaryBreakdown['allowance_breakdown'],
                'legacy_gross_salary_snapshot' => $salaryBreakdown['legacy_gross_salary'],
                'calculated_gross_salary_snapshot' => $salaryBreakdown['calculated_gross'],
                'gross_difference_snapshot' => $salaryBreakdown['gross_difference'],
                'source' => $placementData['source'] ?? 'staff_management',
                'is_current' => true,
                'effective_from' => $placementData['effective_from'] ?? now()->toDateString(),
                'effective_to' => $placementData['effective_to'] ?? null,
            ]);

            $this->auditLogService->log('staff.salary_placement.updated', $staff, $currentPlacement?->toArray() ?? [], $placement->toArray(), [
                'source' => 'staff_management.salary_placement',
                'allowance_codes' => $eligibleAllowanceCodes,
            ]);

            return $placement->fresh('salaryScale');
        });
    }
}
