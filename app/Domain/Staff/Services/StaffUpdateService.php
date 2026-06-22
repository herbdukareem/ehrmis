<?php

namespace App\Domain\Staff\Services;

use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffPersonalDetail;
use App\Domain\Staff\Models\StaffQualification;
use App\Domain\Staff\Models\StaffStatusHistory;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

class StaffUpdateService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected StaffAllowanceService $staffAllowanceService,
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
     * @param  array<string, mixed>  $updates
     */
    public function resolveFlaggedIssues(Staff $staff, array $updates, User $user): Staff
    {
        return DB::transaction(function () use ($staff, $updates, $user): Staff {
            $resolvedFields = [];

            if (! empty($updates['date_of_birth'])) {
                $staff->forceFill(['date_of_birth' => $updates['date_of_birth']])->save();
            }

            $currentEmployment = $staff->currentEmployment()->first();
            $cadreId = $updates['cadre_id'] ?? null;
            $rankId = $updates['rank_id'] ?? null;

            if ($currentEmployment && ($cadreId || $rankId)) {
                $nextCadreId = $cadreId ?: $currentEmployment->cadre_id;
                $nextRankId = $rankId ?: $currentEmployment->rank_id;

                if ($nextCadreId !== $currentEmployment->cadre_id || $nextRankId !== $currentEmployment->rank_id) {
                    $this->createEmploymentRecord($staff, [
                        'mda_id' => $currentEmployment->mda_id,
                        'department_id' => $currentEmployment->department_id,
                        'station_id' => $currentEmployment->station_id,
                        'location_name' => $currentEmployment->location_name,
                        'cadre_id' => $nextCadreId,
                        'rank_id' => $nextRankId,
                        'staff_category' => $currentEmployment->staff_category,
                        'initial_rank' => $currentEmployment->initial_rank,
                        'date_first_appointment' => $currentEmployment->date_first_appointment,
                        'date_last_promotion' => $currentEmployment->date_last_promotion,
                        'expected_retirement_date' => $currentEmployment->expected_retirement_date,
                        'next_promotion_date' => $currentEmployment->next_promotion_date,
                        'employment_status' => $currentEmployment->employment_status,
                    ]);
                }

                if ($cadreId) {
                    $resolvedFields[] = 'cadre';
                }

                if ($rankId) {
                    $resolvedFields[] = 'rank';
                }
            }

            if ($updates['qualification_type_id'] ?? null) {
                $qualificationType = QualificationType::query()
                    ->forMda((int) $staff->mda_id)
                    ->findOrFail($updates['qualification_type_id']);

                $this->storeQualification($staff, [
                    'qualification_type_id' => $qualificationType->id,
                    'qualification_name' => $qualificationType->name,
                    'highest_qualification_name' => $qualificationType->name,
                    'is_highest' => true,
                    'source' => 'staff_management',
                ]);

                $resolvedFields[] = 'qualification';
            }

            if ($updates['allowances'] ?? null) {
                $this->staffAllowanceService->syncAssignments($staff, collect($updates['allowances'])
                    ->map(fn (array $assignment): array => [
                        'allowance_type_id' => $assignment['allowance_type_id'],
                        'is_eligible' => (bool) ($assignment['is_eligible'] ?? false),
                        'source' => 'staff_management',
                        'effective_from' => now()->toDateString(),
                    ])
                    ->all());

                $resolvedFields[] = 'call_allowance';
            }

            if ($resolvedFields !== []) {
                LegacyStaffImportError::query()
                    ->whereIn('row_id', $staff->importRows()->pluck('id'))
                    ->whereIn('field', $resolvedFields)
                    ->whereNull('resolved_at')
                    ->whereNull('ignored_at')
                    ->update([
                        'resolved_at' => now(),
                        'resolved_by' => $user->id,
                        'resolution_notes' => 'Resolved via staff record update.',
                        'updated_at' => now(),
                    ]);
            }

            return $staff->fresh([
                'mda',
                'personalDetail',
                'currentEmployment.department',
                'currentEmployment.station',
                'currentEmployment.cadre',
                'currentEmployment.rank',
                'currentSalaryPlacement.salaryScale',
                'qualifications.qualificationType',
                'allowanceAssignments.allowanceType',
            ]);
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
