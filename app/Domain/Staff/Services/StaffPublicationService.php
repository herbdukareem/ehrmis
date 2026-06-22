<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffPersonalDetail;
use App\Domain\Staff\Models\StaffQualification;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Domain\Staff\Models\StaffStatusHistory;
use App\Services\AuditLogService;

class StaffPublicationService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected SalaryCalculationService $salaryCalculationService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $normalizedRow
     * @return array{staff: \App\Domain\Staff\Models\Staff, created: bool}
     */
    public function publish(array $normalizedRow, ?Staff $matchedStaff = null): array
    {
        $mdaId = $normalizedRow['mda_id'] ?? null;

        if (! $mdaId) {
            throw new \InvalidArgumentException('Imported staff rows must resolve an MDA before publication.');
        }

        $staff = $matchedStaff ?? Staff::query()
            ->forMda((int) $mdaId)
            ->firstOrNew([
                'staff_number' => $normalizedRow['staff_number'],
            ]);

        $wasExisting = $staff->exists;
        $before = $wasExisting ? $staff->toArray() : [];

        $staff->fill([
            'mda_id' => $normalizedRow['mda_id'],
            'staff_number' => $normalizedRow['staff_number'],
            'legacy_staff_id' => $normalizedRow['legacy_staff_id'],
            'legacy_master_staff_id' => $normalizedRow['legacy_master_staff_id'],
            'legacy_cno' => $normalizedRow['legacy_cno'],
            'legacy_psn' => $normalizedRow['legacy_psn'],
            'legacy_cno_psn' => $normalizedRow['legacy_cno_psn'],
            'surname' => $normalizedRow['surname'],
            'first_name' => $normalizedRow['first_name'],
            'middle_name' => $normalizedRow['middle_name'],
            'full_name' => $normalizedRow['full_name'],
            'sex' => $normalizedRow['sex'],
            'date_of_birth' => $normalizedRow['date_of_birth'],
            'status' => $normalizedRow['status'],
        ]);
        $staff->save();

        StaffPersonalDetail::query()->updateOrCreate(
            ['staff_id' => $staff->id],
            [
                'lga' => $normalizedRow['lga'],
                'state_of_origin' => $normalizedRow['state_of_origin'],
                'phone' => $normalizedRow['phone'],
                'email' => $normalizedRow['email'],
                'address' => $normalizedRow['address'],
                'marital_status' => $normalizedRow['marital_status'],
                'file_no' => $normalizedRow['file_no'],
            ],
        );

        StaffEmployment::query()->updateOrCreate(
            ['staff_id' => $staff->id, 'is_current' => true],
            [
                'mda_id' => $normalizedRow['mda_id'],
                'department_id' => $normalizedRow['department_id'],
                'station_id' => $normalizedRow['station_id'],
                'location_name' => $normalizedRow['location_name'],
                'cadre_id' => $normalizedRow['cadre_id'],
                'rank_id' => $normalizedRow['rank_id'],
                'staff_category' => $normalizedRow['staff_category'],
                'initial_rank' => $normalizedRow['initial_rank'],
                'date_first_appointment' => $normalizedRow['date_first_appointment'],
                'date_last_promotion' => $normalizedRow['date_last_promotion'],
                'expected_retirement_date' => $normalizedRow['resolved_expected_retirement_date']
                    ?? $normalizedRow['legacy_expected_retirement_date']
                    ?? $normalizedRow['computed_expected_retirement_date'],
                'next_promotion_date' => $normalizedRow['next_promotion_date'] ?? $normalizedRow['computed_next_promotion_date'],
                'employment_status' => $normalizedRow['employment_status'],
                'effective_from' => $normalizedRow['date_first_appointment'],
                'effective_to' => null,
            ],
        );

        $eligibleAllowanceCodes = collect($normalizedRow['allowances'] ?? [])
            ->filter(fn (array $allowance): bool => (bool) ($allowance['is_eligible'] ?? false))
            ->keys()
            ->values()
            ->all();

        $salaryBreakdown = null;

        if (($normalizedRow['salary_scale_code'] ?? null) && ($normalizedRow['level'] ?? null) !== null && ($normalizedRow['step'] ?? null) !== null) {
            $salaryBreakdown = $this->salaryCalculationService->calculateGrossForPlacement(
                (string) $normalizedRow['salary_scale_code'],
                (int) $normalizedRow['level'],
                (int) $normalizedRow['step'],
                $eligibleAllowanceCodes,
                (int) $normalizedRow['mda_id'],
            );
        }

        StaffSalaryPlacement::query()->updateOrCreate(
            ['staff_id' => $staff->id, 'is_current' => true],
            [
                'salary_scale_id' => $normalizedRow['salary_scale_id'],
                'level' => $normalizedRow['level'],
                'step' => $normalizedRow['step'],
                'basic_salary' => $salaryBreakdown['basic_salary'] ?? $normalizedRow['basic_salary'],
                'gross_salary' => $salaryBreakdown['calculated_gross'] ?? $normalizedRow['gross_salary'],
                'source' => 'legacy_snapshot',
                'effective_from' => $normalizedRow['date_last_promotion'] ?? $normalizedRow['date_first_appointment'],
                'effective_to' => null,
            ],
        );

        StaffQualification::query()->updateOrCreate(
            ['staff_id' => $staff->id, 'source' => 'legacy_import', 'is_highest' => true],
            [
                'qualification_type_id' => $normalizedRow['qualification_type_id'],
                'qualification_name' => $normalizedRow['qualification_name'],
                'highest_qualification_name' => $normalizedRow['highest_qualification_name'],
                'specialization' => $normalizedRow['specialization'],
            ],
        );

        $allowanceTypes = AllowanceType::query()
            ->forMda((int) $normalizedRow['mda_id'])
            ->whereIn('code', array_keys($normalizedRow['allowances'] ?? []))
            ->get()
            ->keyBy('code');

        foreach ($normalizedRow['allowances'] as $allowanceCode => $allowanceData) {
            $allowanceType = $allowanceTypes->get($allowanceCode);

            if (! $allowanceType) {
                continue;
            }

            StaffAllowanceAssignment::query()->updateOrCreate(
                [
                    'staff_id' => $staff->id,
                    'allowance_type_id' => $allowanceType->id,
                    'source' => 'legacy_import',
                ],
                [
                    'allowance_type_id' => $allowanceType->id,
                    'is_eligible' => $allowanceData['is_eligible'],
                    'source' => 'legacy_import',
                    'effective_from' => $normalizedRow['date_first_appointment'],
                    'effective_to' => null,
                ],
            );
        }

        $statuses = array_unique(array_filter([
            'imported',
            $normalizedRow['status'],
            $normalizedRow['is_retired'] ? 'retired' : null,
            $normalizedRow['is_duplicate'] ? 'duplicate' : null,
        ]));

        foreach ($statuses as $status) {
            StaffStatusHistory::query()->firstOrCreate(
                [
                    'staff_id' => $staff->id,
                    'status' => $status,
                    'effective_from' => $normalizedRow['date_first_appointment'],
                ],
                [
                    'reason' => 'Imported from legacy staff pipeline',
                    'metadata' => [
                        'source_table' => $normalizedRow['source_table'],
                        'legacy_staff_id' => $normalizedRow['legacy_staff_id'],
                        'legacy_master_staff_id' => $normalizedRow['legacy_master_staff_id'],
                    ],
                ],
            );
        }

        if ($wasExisting) {
            $this->auditLogService->logUpdated($staff, $before, ['source' => 'legacy_staff_import']);
        } else {
            $this->auditLogService->logCreated($staff, ['source' => 'legacy_staff_import']);
        }

        return [
            'staff' => $staff,
            'created' => ! $wasExisting,
        ];
    }
}
