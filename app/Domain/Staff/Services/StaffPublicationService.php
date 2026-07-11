<?php

namespace App\Domain\Staff\Services;

use App\Domain\Legacy\Support\LegacyIdentifier;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffPersonalDetail;
use App\Domain\Staff\Models\StaffQualification;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Domain\Staff\Models\StaffStatusHistory;
use App\Services\AuditLogService;
use Illuminate\Support\Str;

class StaffPublicationService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected SalaryCalculationService $salaryCalculationService,
        protected AllowanceTypeProvisioningService $allowanceTypeProvisioningService,
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

        $matchedStaff = $this->resolveMatchedStaff($normalizedRow, $matchedStaff);
        $staffNumber = $this->resolveStaffNumber($normalizedRow, $matchedStaff);

        $staff = $matchedStaff
            ?? $this->findExistingStaffByNumberAndName((int) $mdaId, $staffNumber, $normalizedRow['full_name'] ?? null)
            ?? new Staff();

        $wasExisting = $staff->exists;
        $before = $wasExisting ? $staff->toArray() : [];
        

        $staff->fill([
            'mda_id' => $normalizedRow['mda_id'],
            'staff_number' => $staffNumber ?? $normalizedRow['staff_number'],
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
                'basic_salary_snapshot' => $salaryBreakdown['basic_salary'] ?? $normalizedRow['basic_salary'],
                'allowance_total_snapshot' => $salaryBreakdown['total_allowances'] ?? null,
                'allowance_breakdown_snapshot' => $salaryBreakdown['allowance_breakdown'] ?? [],
                'legacy_gross_salary_snapshot' => $salaryBreakdown['legacy_gross_salary'] ?? $normalizedRow['gross_salary'],
                'calculated_gross_salary_snapshot' => $salaryBreakdown['calculated_gross'] ?? $normalizedRow['gross_salary'],
                'gross_difference_snapshot' => $salaryBreakdown['gross_difference'] ?? null,
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

        $allowances = is_array($normalizedRow['allowances'] ?? null)
            ? $normalizedRow['allowances']
            : [];

        $allowanceTypes = collect(
            $this->allowanceTypeProvisioningService
                ->ensureForMda((int) $normalizedRow['mda_id'], array_keys($allowances))['types']
        )->keyBy('code');

        foreach ($allowances as $allowanceCode => $allowanceData) {
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
                    'effective_from' => $this->resolveStatusEffectiveFrom($status, $normalizedRow),
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

    /**
     * @param  array<string, mixed>  $normalizedRow
     */
    protected function resolveStatusEffectiveFrom(string $status, array $normalizedRow): ?string
    {
        if ($status === 'retired') {
            return $normalizedRow['resolved_expected_retirement_date']
                ?? $normalizedRow['legacy_expected_retirement_date']
                ?? $normalizedRow['computed_expected_retirement_date']
                ?? $normalizedRow['date_first_appointment'];
        }

        return $normalizedRow['date_first_appointment'];
    }


    protected function resolveStaffNumber(array $normalizedRow, ?Staff $matchedStaff = null): string
    {
        $staffNumber = $normalizedRow['staff_number']
            ?? $normalizedRow['legacy_cno_psn']
            ?? $normalizedRow['legacy_cno']
            ?? $normalizedRow['legacy_psn']
            ?? null;

        $legacyCnoPsn = LegacyIdentifier::normalize($normalizedRow['legacy_cno_psn'] ?? null);
        $mdaId = $normalizedRow['mda_id'] ?? null;

        if (! $staffNumber) {
            throw new \InvalidArgumentException('Staff number is required for publication.');
        }

        if (! $mdaId) {
            throw new \InvalidArgumentException('MDA is required before resolving staff number.');
        }

        $existingStaff = Staff::query()
            ->forMda((int) $mdaId)
            ->when($matchedStaff, fn ($query) => $query->whereKeyNot($matchedStaff->id))
            ->where('staff_number', $staffNumber)
            ->first();

        if (! $existingStaff || $this->samePersonByName($existingStaff->full_name, $normalizedRow['full_name'] ?? null)) {
            return $staffNumber;
        }

        if ($legacyCnoPsn) {
            $legacyExistingStaff = Staff::query()
                ->forMda((int) $mdaId)
                ->when($matchedStaff, fn ($query) => $query->whereKeyNot($matchedStaff->id))
                ->where('staff_number', $legacyCnoPsn)
                ->first();

            if (! $legacyExistingStaff || $this->samePersonByName($legacyExistingStaff->full_name, $normalizedRow['full_name'] ?? null)) {
                return $legacyCnoPsn;
            }
        }

        return $this->generateImportedStaffNumber($mdaId, $legacyCnoPsn ?: $staffNumber, $matchedStaff);
    }

    protected function findExistingStaffByNumberAndName(int $mdaId, string $staffNumber, ?string $fullName): ?Staff
    {
        $existingStaff = Staff::query()
            ->forMda($mdaId)
            ->where('staff_number', $staffNumber)
            ->first();

        if (! $existingStaff) {
            return null;
        }

        return $this->samePersonByName($existingStaff->full_name, $fullName) ? $existingStaff : null;
    }

    protected function samePersonByName(?string $existingFullName, ?string $incomingFullName): bool
    {
        $existing = $this->normalizeComparableName($existingFullName);
        $incoming = $this->normalizeComparableName($incomingFullName);

        return $existing !== null && $incoming !== null && $existing === $incoming;
    }

    protected function normalizeComparableName(?string $fullName): ?string
    {
        $normalized = Str::of((string) $fullName)
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/i', ' ')
            ->squish()
            ->value();

        return $normalized === '' ? null : $normalized;
    }

    protected function resolveMatchedStaff(array $normalizedRow, ?Staff $matchedStaff = null): ?Staff
    {
        if ($matchedStaff) {
            return $matchedStaff;
        }

        $mdaId = (int) ($normalizedRow['mda_id'] ?? 0);

        if (! $mdaId) {
            return null;
        }

        $query = Staff::query()->forMda($mdaId);

        $legacyCnoPsn = LegacyIdentifier::normalize($normalizedRow['legacy_cno_psn'] ?? null);
        $legacyCno = LegacyIdentifier::normalize($normalizedRow['legacy_cno'] ?? null);
        if ($legacyCnoPsn !== null) {
            $staff = (clone $query)
                ->where('legacy_cno_psn', $legacyCnoPsn)
                ->first();

            if ($staff) {
                return $staff;
            }
        }

        if ($legacyCno !== null) {
            $staff = (clone $query)
                ->where('legacy_cno', $legacyCno)
                ->first();

            if ($staff) {
                return $staff;
            }
        }

        $normalizedName = $this->normalizeComparableName($normalizedRow['full_name'] ?? null);
        $dateOfBirth = $normalizedRow['date_of_birth'] ?? null;

        if ($normalizedName !== null && $dateOfBirth) {
            return (clone $query)
                ->whereDate('date_of_birth', $dateOfBirth)
                ->get()
                ->first(fn (Staff $staff): bool => $this->samePersonByName($staff->full_name, $normalizedRow['full_name'] ?? null));
        }

        return null;
    }

    protected function generateImportedStaffNumber(int $mdaId, string $baseStaffNumber, ?Staff $matchedStaff = null): string
    {
        $base = Str::upper(Str::of($baseStaffNumber)->replaceMatches('/[^A-Z0-9]+/i', '')->value());
        $base = $base !== '' ? $base : 'IMPORTED';
        $candidate = $base;
        $suffix = 2;

        while (Staff::query()
            ->forMda($mdaId)
            ->when($matchedStaff, fn ($query) => $query->whereKeyNot($matchedStaff->id))
            ->where('staff_number', $candidate)
            ->exists()) {
            $candidate = $base.'-ALT'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
