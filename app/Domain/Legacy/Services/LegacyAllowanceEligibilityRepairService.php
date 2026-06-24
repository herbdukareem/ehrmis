<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Domain\Staff\Services\AllowanceTypeProvisioningService;
use Illuminate\Support\Facades\DB;

class LegacyAllowanceEligibilityRepairService
{
    public function __construct(
        protected LegacyStaffRowNormalizer $normalizer,
        protected AllowanceTypeProvisioningService $allowanceTypeProvisioningService,
    ) {
    }

    /**
     * @return array{rows_processed: int, rows_changed: int, assignments_updated: int, assignments_created: int}
     */
    public function repair(): array
    {
        $summary = [
            'rows_processed' => 0,
            'rows_changed' => 0,
            'assignments_updated' => 0,
            'assignments_created' => 0,
        ];
        $eligibilityByStaff = [];
        $effectiveFromByStaff = [];
        $allowanceTypeIdsByStaff = [];

        LegacyStaffImportRow::query()
            ->whereNotNull('published_staff_id')
            ->chunkById(100, function ($rows) use (&$summary, &$eligibilityByStaff, &$effectiveFromByStaff, &$allowanceTypeIdsByStaff): void {
                DB::transaction(function () use ($rows, &$summary, &$eligibilityByStaff, &$effectiveFromByStaff, &$allowanceTypeIdsByStaff): void {
                    foreach ($rows as $row) {
                        $summary['rows_processed']++;
                        $rawPayload = $row->raw_payload ?? [];
                        $normalizedPayload = $row->normalized_payload ?? [];
                        $result = $this->normalizer->normalizeAllowanceEligibility(
                            $rawPayload['source_row'] ?? [],
                            $rawPayload['master_row'] ?? null,
                        );

                        if (($normalizedPayload['allowances'] ?? []) !== $result['allowances']) {
                            $normalizedPayload['allowances'] = $result['allowances'];
                            $row->forceFill(['normalized_payload' => $normalizedPayload])->save();
                            $summary['rows_changed']++;
                        }

                        $allowanceTypes = collect(
                            $this->allowanceTypeProvisioningService
                                ->ensureForMda((int) $row->mda_id, array_keys($result['allowances']))['types']
                        )->keyBy('code');

                        foreach ($result['allowances'] as $code => $allowance) {
                            $eligibilityByStaff[$row->published_staff_id][$code] =
                                ($eligibilityByStaff[$row->published_staff_id][$code] ?? false)
                                || $allowance['is_eligible'];
                            $allowanceTypeIdsByStaff[$row->published_staff_id][$code] ??= $allowanceTypes->get($code)?->id;
                        }

                        $effectiveFromByStaff[$row->published_staff_id] ??= $normalizedPayload['date_first_appointment'] ?? null;
                    }
                });
            });

        DB::transaction(function () use (&$summary, $eligibilityByStaff, $effectiveFromByStaff, $allowanceTypeIdsByStaff): void {
            foreach ($eligibilityByStaff as $staffId => $allowances) {
                foreach ($allowances as $code => $isEligible) {
                    $allowanceTypeId = $allowanceTypeIdsByStaff[$staffId][$code] ?? null;

                    if (! $allowanceTypeId) {
                        continue;
                    }

                    $assignment = StaffAllowanceAssignment::query()->firstOrNew([
                        'staff_id' => $staffId,
                        'allowance_type_id' => $allowanceTypeId,
                        'source' => 'legacy_import',
                    ]);
                    $wasCreated = ! $assignment->exists;
                    $eligibilityChanged = $wasCreated || (bool) $assignment->is_eligible !== $isEligible;

                    $assignment->fill([
                        'is_eligible' => $isEligible,
                        'effective_from' => $effectiveFromByStaff[$staffId] ?? null,
                        'effective_to' => null,
                    ])->save();

                    if ($wasCreated) {
                        $summary['assignments_created']++;
                    } elseif ($eligibilityChanged) {
                        $summary['assignments_updated']++;
                    }
                }
            }
        });

        return $summary;
    }
}
