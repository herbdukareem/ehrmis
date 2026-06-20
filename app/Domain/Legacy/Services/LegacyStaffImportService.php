<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportPublication;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Staff\Services\StaffPublicationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LegacyStaffImportService
{
    public function __construct(
        protected LegacyStaffRowNormalizer $normalizer,
        protected LegacyStaffRowValidator $validator,
        protected LegacyStaffIdentityMatcher $identityMatcher,
        protected StaffPublicationService $publicationService,
    ) {
    }

    /**
     * @param  array{dry_run?: bool, limit?: int, mda?: ?string, include_retired?: bool, only_retired?: bool, source?: string, publish?: bool}  $options
     * @return array<string, mixed>
     */
    public function import(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = max(1, (int) ($options['limit'] ?? 100));
        $mdaFilter = $options['mda'] ?? null;
        $includeRetired = (bool) ($options['include_retired'] ?? false);
        $onlyRetired = (bool) ($options['only_retired'] ?? false);
        $source = in_array($options['source'] ?? 'staff_list', ['staff_list', 'master_staff_list'], true)
            ? (string) ($options['source'] ?? 'staff_list')
            : 'staff_list';
        $publish = (bool) ($options['publish'] ?? false);

        $rows = $this->readLegacyRows($source, $limit, $mdaFilter, $includeRetired, $onlyRetired);
        $summary = $this->makeSummary($dryRun, $source, $limit, $publish);
        $summary['rows_read'] = count($rows);

        if (! $dryRun) {
            DB::beginTransaction();
        }

        try {
            $batch = ! $dryRun
                ? LegacyStaffImportBatch::query()->create([
                    'source_database' => (string) config('database.connections.legacy.database', 'legacy'),
                    'source_table' => $source,
                    'status' => $publish ? 'publishing' : 'staging',
                    'started_at' => now(),
                ])
                : null;

            foreach ($rows as $legacyRow) {
                $masterRow = $source === 'staff_list' ? $this->normalizer->findMasterRow($legacyRow) : null;
                $normalized = $this->normalizer->normalize($legacyRow, $source, $masterRow, $publish);
                $issues = $this->validator->validate($normalized);
                $hasErrors = $this->validator->hasErrors($issues);
                $matchedStaff = $this->identityMatcher->match($normalized);
                $normalized['matched_staff_id'] = $matchedStaff?->id;

                if ($matchedStaff && empty($normalized['issues'])) {
                    $issues[] = [
                        'field' => 'identity',
                        'error_code' => 'matched_existing_staff',
                        'message' => 'Legacy row matched an existing canonical staff record.',
                        'severity' => 'warning',
                    ];
                }

                $rowStatus = $hasErrors ? 'invalid' : ($publish ? 'ready_to_publish' : 'staged');
                $publishedStaffId = null;

                $this->tallySummary($summary, $normalized, $issues, $rowStatus);

                if (! $dryRun) {
                    $stagedRow = LegacyStaffImportRow::query()->create([
                        'batch_id' => $batch->id,
                        'legacy_staff_id' => $normalized['legacy_staff_id'],
                        'legacy_master_staff_id' => $normalized['legacy_master_staff_id'],
                        'mda_id' => $normalized['mda_id'],
                        'staff_number' => $normalized['staff_number'],
                        'legacy_cno' => $normalized['legacy_cno'],
                        'legacy_psn' => $normalized['legacy_psn'],
                        'legacy_cno_psn' => $normalized['legacy_cno_psn'],
                        'full_name' => $normalized['full_name'],
                        'raw_payload' => [
                            'source_row' => $legacyRow,
                            'master_row' => $masterRow,
                        ],
                        'normalized_payload' => $normalized,
                        'dedupe_key' => $normalized['dedupe_key'],
                        'status' => $rowStatus,
                        'matched_staff_id' => $matchedStaff?->id,
                        'published_staff_id' => null,
                        'department_id' => $normalized['department_id'],
                        'department_name' => $normalized['department_name'],
                        'station_id' => $normalized['station_id'],
                        'station_name' => $normalized['station_name'],
                        'cadre_id' => $normalized['cadre_id'],
                        'cadre_name' => $normalized['cadre_name'],
                        'rank_id' => $normalized['rank_id'],
                        'rank_name' => $normalized['rank_name'],
                        'salary_scale_id' => $normalized['salary_scale_id'],
                        'salary_scale_code' => $normalized['salary_scale_code'],
                        'level' => $normalized['level'],
                        'step' => $normalized['step'],
                    ]);

                    $summary['rows_staged']++;

                    foreach ($issues as $issue) {
                        LegacyStaffImportError::query()->create([
                            'batch_id' => $batch->id,
                            'row_id' => $stagedRow->id,
                            'field' => $issue['field'] ?? null,
                            'error_code' => $issue['error_code'],
                            'message' => $issue['message'],
                            'severity' => $issue['severity'],
                        ]);
                    }

                    if ($publish && ! $hasErrors) {
                        $published = $this->publicationService->publish($normalized, $matchedStaff);
                        $publishedStaffId = $published['staff']->id;
                        $summary['rows_published']++;
                        $summary[$published['created'] ? 'published_created' : 'published_updated']++;

                        $stagedRow->forceFill([
                            'status' => 'published',
                            'published_staff_id' => $publishedStaffId,
                        ])->save();
                    }
                } else {
                    $summary['rows_staged']++;

                    if ($publish && ! $hasErrors) {
                        $summary['rows_published']++;
                    }
                }
            }

            if (! $dryRun) {
                $batch->forceFill([
                    'status' => $publish ? 'completed' : 'staged',
                    'completed_at' => now(),
                    'summary' => $summary,
                ])->save();

                if ($publish) {
                    LegacyStaffImportPublication::query()->create([
                        'batch_id' => $batch->id,
                        'published_by' => Auth::id(),
                        'published_at' => now(),
                        'summary' => $summary,
                    ]);
                }

                DB::commit();
            }
        } catch (\Throwable $throwable) {
            if (! $dryRun) {
                DB::rollBack();
            }

            $summary['errors'][] = $throwable->getMessage();

            throw $throwable;
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function readLegacyRows(string $source, int $limit, ?string $mdaFilter, bool $includeRetired, bool $onlyRetired): array
    {
        $query = DB::connection('legacy')->table($source)->orderBy('id');

        if ($onlyRetired) {
            $query->where('is_retired', 1);
        } elseif (! $includeRetired) {
            $query->where('is_retired', 0);
        }

        if ($mdaFilter) {
            $query->where('mda', $mdaFilter);
        }

        return $query->limit($limit)->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function makeSummary(bool $dryRun, string $source, int $limit, bool $publish): array
    {
        return [
            'dry_run' => $dryRun,
            'source' => $source,
            'limit' => $limit,
            'publish' => $publish,
            'rows_read' => 0,
            'rows_staged' => 0,
            'rows_published' => 0,
            'published_created' => 0,
            'published_updated' => 0,
            'rows_ready_to_publish' => 0,
            'invalid_rows' => 0,
            'active_staff' => 0,
            'retired_staff' => 0,
            'duplicate_risk_rows' => 0,
            'matched_existing_staff_count' => 0,
            'rows_with_warnings' => 0,
            'rows_with_errors' => 0,
            'missing_mda' => 0,
            'missing_department' => 0,
            'missing_station' => 0,
            'missing_cadre' => 0,
            'missing_rank' => 0,
            'missing_salary_scale' => 0,
            'missing_qualification' => 0,
            'missing_level' => 0,
            'missing_step' => 0,
            'cadre_auto_created' => 0,
            'rank_auto_created' => 0,
            'edor_mismatch_count' => 0,
            'next_promotion_mismatch_count' => 0,
            'call_allowance_resolved' => 0,
            'call_allowance_unresolved' => 0,
            'row_status_counts' => [],
            'warning_counts' => [],
            'error_counts' => [],
            'warnings' => [],
            'errors' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<int, array{error_code: string, message: string, severity: string}>  $issues
     */
    protected function tallySummary(array &$summary, array $normalized, array $issues, string $rowStatus): void
    {
        if (! empty($normalized['is_retired'])) {
            $summary['retired_staff']++;
        } else {
            $summary['active_staff']++;
        }

        if (! empty($normalized['is_duplicate'])) {
            $summary['duplicate_risk_rows']++;
        }

        $summary['row_status_counts'][$rowStatus] = ($summary['row_status_counts'][$rowStatus] ?? 0) + 1;

        if ($rowStatus === 'invalid') {
            $summary['invalid_rows']++;
        }

        if ($rowStatus === 'ready_to_publish') {
            $summary['rows_ready_to_publish']++;
        }

        $hasResolvedCallAllowance = collect($normalized['allowances'] ?? [])
            ->filter(fn (array $allowance): bool => (bool) ($allowance['is_eligible'] ?? false))
            ->keys()
            ->contains(fn (string $code): bool => in_array($code, ['call_doctor', 'call_pharm_lab', 'call_opt_odd', 'call_nurse_others'], true));

        if ($hasResolvedCallAllowance) {
            $summary['call_allowance_resolved']++;
        }

        $rowHasWarning = false;
        $rowHasError = false;

        foreach ($issues as $issue) {
            $severity = ($issue['severity'] ?? 'warning') === 'error' ? 'error' : 'warning';

            if ($severity === 'error') {
                $summary['error_counts'][$issue['error_code']] = ($summary['error_counts'][$issue['error_code']] ?? 0) + 1;
            } else {
                $summary['warning_counts'][$issue['error_code']] = ($summary['warning_counts'][$issue['error_code']] ?? 0) + 1;
            }

            if (($issue['severity'] ?? 'warning') === 'error') {
                $rowHasError = true;
            } else {
                $rowHasWarning = true;
                $summary['warnings'][] = $issue['message'];
            }

            match ($issue['error_code']) {
                'missing_mda' => $summary['missing_mda']++,
                'missing_department' => $summary['missing_department']++,
                'missing_station' => $summary['missing_station']++,
                'missing_cadre' => $summary['missing_cadre']++,
                'missing_rank' => $summary['missing_rank']++,
                'missing_salary_scale' => $summary['missing_salary_scale']++,
                'missing_qualification' => $summary['missing_qualification']++,
                'missing_level' => $summary['missing_level']++,
                'missing_step' => $summary['missing_step']++,
                'edor_mismatch' => $summary['edor_mismatch_count']++,
                'next_promotion_mismatch' => $summary['next_promotion_mismatch_count']++,
                'matched_existing_staff' => $summary['matched_existing_staff_count']++,
                'call_allowance_unresolved' => $summary['call_allowance_unresolved']++,
                'cadre_auto_created' => $summary['cadre_auto_created']++,
                'rank_auto_created' => $summary['rank_auto_created']++,
                default => null,
            };
        }

        if ($rowHasWarning) {
            $summary['rows_with_warnings']++;
        }

        if ($rowHasError) {
            $summary['rows_with_errors']++;
        }
    }
}
