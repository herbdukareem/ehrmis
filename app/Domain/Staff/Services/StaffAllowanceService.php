<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StaffAllowanceService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     */
    public function syncAssignments(Staff $staff, array $assignments): void
    {
        DB::transaction(function () use ($staff, $assignments): void {
            $before = $staff->allowanceAssignments()->with('allowanceType')->get()->toArray();
            $typeIds = AllowanceType::query()->pluck('id')->all();

            foreach ($assignments as $assignment) {
                if (array_key_exists('allowance_code', $assignment)) {
                    throw new InvalidArgumentException('Fixed allowance code payloads are not supported. Use allowance_type_id.');
                }

                $allowanceTypeId = (int) ($assignment['allowance_type_id'] ?? 0);

                if (! in_array($allowanceTypeId, $typeIds, true)) {
                    continue;
                }

                $existing = StaffAllowanceAssignment::query()
                    ->where('staff_id', $staff->id)
                    ->where('allowance_type_id', $allowanceTypeId)
                    ->where(function ($query): void {
                        $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString());
                    })
                    ->latest('id')
                    ->first();

                $effectiveFrom = $assignment['effective_from'] ?? now()->toDateString();
                $newEligibility = (bool) ($assignment['is_eligible'] ?? false);

                if ($existing && (bool) $existing->is_eligible === $newEligibility) {
                    continue;
                }

                if ($existing) {
                    $existing->forceFill([
                        'effective_to' => $assignment['close_previous_effective_to'] ?? $effectiveFrom,
                    ])->save();
                }

                StaffAllowanceAssignment::query()->create([
                    'staff_id' => $staff->id,
                    'allowance_type_id' => $allowanceTypeId,
                    'is_eligible' => $newEligibility,
                    'source' => $assignment['source'] ?? 'staff_management',
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $assignment['effective_to'] ?? null,
                ]);
            }

            $this->auditLogService->log('staff.allowances.synced', $staff, $before, $staff->allowanceAssignments()->with('allowanceType')->get()->toArray(), [
                'source' => 'staff_management.allowances',
            ]);
        });
    }
}
