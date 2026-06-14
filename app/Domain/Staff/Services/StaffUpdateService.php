<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffPersonalDetail;
use App\Domain\Staff\Models\StaffQualification;
use App\Domain\Staff\Models\StaffStatusHistory;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

class StaffUpdateService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $staffData
     * @param  array<string, mixed>  $personalDetailData
     * @param  array<string, mixed>|null  $statusData
     */
    public function updateStaff(Staff $staff, array $staffData, array $personalDetailData = [], ?array $statusData = null): Staff
    {
        return DB::transaction(function () use ($staff, $staffData, $personalDetailData, $statusData): Staff {
            $before = $staff->load('personalDetail')->toArray();
            $originalStatus = $staff->status;

            $staff->fill($staffData)->save();

            if ($personalDetailData !== []) {
                StaffPersonalDetail::query()->updateOrCreate(
                    ['staff_id' => $staff->id],
                    $personalDetailData,
                );
            }

            if ($statusData && ($statusData['status'] ?? null) && $originalStatus !== $statusData['status']) {
                StaffStatusHistory::query()->create([
                    'staff_id' => $staff->id,
                    'status' => $statusData['status'],
                    'reason' => $statusData['reason'] ?? 'Updated through staff management module',
                    'effective_from' => $statusData['effective_from'] ?? now()->toDateString(),
                    'metadata' => [
                        'source' => 'staff_management.update',
                    ],
                ]);
            }

            $this->auditLogService->logUpdated($staff->fresh('personalDetail'), $before, [
                'source' => 'staff_management.update',
            ]);

            return $staff->fresh([
                'mda',
                'personalDetail',
                'currentEmployment.department',
                'currentEmployment.station',
                'currentEmployment.cadre',
                'currentEmployment.rank',
                'currentSalaryPlacement.salaryScale',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $employmentData
     */
    public function createEmploymentRecord(Staff $staff, array $employmentData): StaffEmployment
    {
        return DB::transaction(function () use ($staff, $employmentData): StaffEmployment {
            $currentEmployment = $staff->currentEmployment()->first();
            $newEffectiveFrom = $employmentData['effective_from'] ?? now()->toDateString();

            if ($currentEmployment) {
                $currentEmployment->forceFill([
                    'is_current' => false,
                    'effective_to' => $employmentData['previous_effective_to'] ?? $newEffectiveFrom,
                ])->save();
            }

            $employment = StaffEmployment::query()->create(array_merge($employmentData, [
                'staff_id' => $staff->id,
                'is_current' => true,
            ]));

            $this->auditLogService->log('staff.employment.updated', $staff, $currentEmployment?->toArray() ?? [], $employment->toArray(), [
                'source' => 'staff_management.employment',
            ]);

            return $employment;
        });
    }

    /**
     * @param  array<string, mixed>  $qualificationData
     */
    public function storeQualification(Staff $staff, array $qualificationData): StaffQualification
    {
        return DB::transaction(function () use ($staff, $qualificationData): StaffQualification {
            if (($qualificationData['is_highest'] ?? false) === true) {
                $staff->qualifications()->update(['is_highest' => false]);
            }

            $qualification = $staff->qualifications()->create($qualificationData);

            $this->auditLogService->log('staff.qualification.updated', $staff, [], $qualification->toArray(), [
                'source' => 'staff_management.qualification',
            ]);

            return $qualification;
        });
    }

    /**
     * @param  array<string, mixed>  $statusData
     */
    public function storeStatusHistory(Staff $staff, array $statusData): StaffStatusHistory
    {
        return DB::transaction(function () use ($staff, $statusData): StaffStatusHistory {
            $before = $staff->toArray();

            $staff->forceFill([
                'status' => $statusData['status'],
            ])->save();

            $history = StaffStatusHistory::query()->create([
                'staff_id' => $staff->id,
                'status' => $statusData['status'],
                'reason' => $statusData['reason'] ?? null,
                'effective_from' => $statusData['effective_from'] ?? now()->toDateString(),
                'metadata' => $statusData['metadata'] ?? ['source' => 'staff_management.status'],
            ]);

            $this->auditLogService->logUpdated($staff, $before, [
                'source' => 'staff_management.status',
                'status_history_id' => $history->id,
            ]);

            return $history;
        });
    }
}
