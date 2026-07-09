<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementSheetGenerationService;
use App\Domain\Movement\Services\MovementWorkbookWorkflowService;
use App\Domain\Staff\Models\PromotionPolicy;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Services\PromotionPolicyCatalogSyncService;
use App\Domain\Staff\Support\PromotionPolicyCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LegacyMovementDataRepairService
{
    public function __construct(
        protected LegacyStaffRowNormalizer $normalizer,
        protected MovementSheetGenerationService $generationService,
        protected MovementWorkbookWorkflowService $workflowService,
        protected PromotionPolicyCatalogSyncService $promotionPolicyCatalogSyncService,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function repair(bool $regenerateWorkbooks = false, bool $reopenApproved = false): array
    {
        $summary = [
            'promotion_policies_created' => 0,
            'promotion_policies_updated' => 0,
            'rows_processed' => 0,
            'employments_updated' => 0,
            'dfa_restored' => 0,
            'dpa_restored' => 0,
            'edor_restored' => 0,
            'dnp_restored' => 0,
            'workbooks_regenerated' => 0,
            'workbooks_skipped' => 0,
        ];
        $this->importPromotionPolicies($summary);

        LegacyStaffImportRow::query()
            ->with('batch')
            ->whereNotNull('published_staff_id')
            ->chunkById(100, function ($rows) use (&$summary): void {
                DB::transaction(function () use ($rows, &$summary): void {
                    foreach ($rows as $row) {
                        $summary['rows_processed']++;
                        $rawPayload = $row->raw_payload ?? [];
                        $normalized = $this->normalizer->normalize(
                            $rawPayload['source_row'] ?? [],
                            $row->batch?->source_table ?? 'staff_list',
                            $rawPayload['master_row'] ?? null,
                        );
                        $employment = StaffEmployment::query()
                            ->where('staff_id', $row->published_staff_id)
                            ->where('is_current', true)
                            ->first();

                        if (! $employment) {
                            continue;
                        }

                        $changes = [];

                        if ($employment->date_first_appointment === null && ($normalized['date_first_appointment'] ?? null)) {
                            $changes['date_first_appointment'] = $normalized['date_first_appointment'];
                            $summary['dfa_restored']++;
                        }

                        if ($employment->date_last_promotion === null && ($normalized['date_last_promotion'] ?? null)) {
                            $changes['date_last_promotion'] = $normalized['date_last_promotion'];
                            $summary['dpa_restored']++;
                        }

                        $expectedRetirementDate = $normalized['resolved_expected_retirement_date']
                            ?? $normalized['legacy_expected_retirement_date']
                            ?? $normalized['computed_expected_retirement_date']
                            ?? null;

                        if ($employment->expected_retirement_date === null && $expectedRetirementDate) {
                            $changes['expected_retirement_date'] = $expectedRetirementDate;
                            $summary['edor_restored']++;
                        }

                        $nextPromotionDate = $normalized['next_promotion_date'] ?? $normalized['computed_next_promotion_date'] ?? null;

                        if ($employment->next_promotion_date === null && $nextPromotionDate) {
                            $changes['next_promotion_date'] = $nextPromotionDate;
                            $summary['dnp_restored']++;
                        }

                        if ($changes !== []) {
                            $employment->forceFill($changes)->save();
                            $summary['employments_updated']++;
                        }

                        $row->forceFill(['normalized_payload' => array_merge($row->normalized_payload ?? [], [
                            'date_first_appointment' => $normalized['date_first_appointment'] ?? null,
                            'date_last_promotion' => $normalized['date_last_promotion'] ?? null,
                            'legacy_expected_retirement_date' => $normalized['legacy_expected_retirement_date'] ?? null,
                            'computed_expected_retirement_date' => $normalized['computed_expected_retirement_date'] ?? null,
                            'resolved_expected_retirement_date' => $expectedRetirementDate,
                            'next_promotion_date' => $normalized['next_promotion_date'] ?? null,
                            'computed_next_promotion_date' => $normalized['computed_next_promotion_date'] ?? null,
                        ])])->save();
                    }
                });
            });

        if (! $regenerateWorkbooks) {
            return $summary;
        }

        foreach (MovementWorkbook::query()->orderBy('id')->get() as $workbook) {
            if (in_array($workbook->status, ['approved', 'locked', 'reviewed'], true)) {
                if (! $reopenApproved) {
                    $summary['workbooks_skipped']++;
                    continue;
                }

                $this->workflowService->reopen($workbook);
                $workbook->refresh();
            }

            $this->generationService->generateForMda(
                $workbook->mda_id,
                $workbook->year,
                $workbook->generated_by,
                $workbook->name,
                $workbook->budget_year,
                $workbook->budget_minimum_step ?? 5,
            );
            $summary['workbooks_regenerated']++;
        }

        return $summary;
    }

    /**
     * @param  array<string, int>  $summary
     */
    protected function importPromotionPolicies(array &$summary): void
    {
        $scales = SalaryScale::query()->get()->keyBy(fn (SalaryScale $scale): string => Str::upper($scale->code));

        if (Schema::connection('legacy')->hasTable('promotion_years')) {
            DB::connection('legacy')
                ->table('promotion_years')
                ->where('status', '1')
                ->orderBy('id')
                ->get()
                ->each(function ($legacyPolicy) use ($scales, &$summary): void {
                    $scaleCode = Str::upper(preg_replace('/[^A-Z0-9]+/i', '', (string) $legacyPolicy->scale) ?? '');
                    $scaleCode = match ($scaleCode) {
                        'GRADELEVEL' => 'GL',
                        'CONHESS' => 'CH',
                        'CONMESS' => 'CM',
                        'SPECIALGRADE' => 'SG',
                        default => $scaleCode,
                    };
                    $salaryScale = $scales->get($scaleCode);

                    if (! $salaryScale) {
                        return;
                    }

                    $policy = PromotionPolicy::query()->updateOrCreate(
                        [
                            'salary_scale_id' => $salaryScale->id,
                            'min_level' => (int) $legacyPolicy->min_level,
                            'max_level' => (int) $legacyPolicy->max_level,
                            'policy_type' => 'normal',
                        ],
                        [
                            'required_years' => (int) $legacyPolicy->year,
                            'description' => 'Imported from legacy table promotion_years',
                            'status' => 'active',
                        ],
                    );

                    $summary[$policy->wasRecentlyCreated ? 'promotion_policies_created' : 'promotion_policies_updated']++;
                });

            return;
        }

        $syncSummary = $this->promotionPolicyCatalogSyncService->syncAll(false);
        $summary['promotion_policies_created'] += $syncSummary['created'];
        $summary['promotion_policies_updated'] += $syncSummary['updated'];
    }
}
