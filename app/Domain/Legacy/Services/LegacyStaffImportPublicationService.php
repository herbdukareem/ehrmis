<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportPublication;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Staff\Services\StaffPublicationService;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LegacyStaffImportPublicationService
{
    protected const PUBLICATION_PROGRESS_KEY = 'publication_progress';

    public function __construct(
        protected StaffPublicationService $staffPublicationService,
        protected AuditLogService $auditLogService,
    ) {
    }

    public function publishBatch(LegacyStaffImportBatch $batch, User $user): array
    {
        do {
            $result = $this->publishBatchSlice($batch->fresh(), $user, null);
        } while (! $result['complete']);

        return $result['summary'];
    }

    public function publishBatchSlice(
        LegacyStaffImportBatch $batch,
        User $user,
        ?int $maxRuntimeSeconds = 45,
        int $chunkSize = 50,
    ): array
    {
        $this->ensureBatchIsApproved($batch);

        $rowsQuery = LegacyStaffImportRow::query()
            ->where('batch_id', $batch->id);

        if (! $user->hasGlobalMdaAccess()) {
            $user->scopeToAccessibleMdas($rowsQuery, 'mda_id');
        }

        $summary = $this->publicationProgressSummary($batch, $rowsQuery);
        $startedAt = microtime(true);

        do {
            $rows = (clone $rowsQuery)
                ->with('matchedStaff')
                ->whereNull('published_staff_id')
                ->whereDoesntHave('errors', fn (Builder $query) => $query->where('severity', 'error')->whereNull('resolved_at'))
                ->orderBy('id')
                ->limit($chunkSize)
                ->get();

            if ($rows->isEmpty()) {
                return [
                    'complete' => true,
                    'summary' => $this->completeBatchPublication($batch, $user, $summary),
                ];
            }

            foreach ($rows as $row) {
                $result = $this->publishRow($row, $user, false, false);

                if ($result['status'] !== 'published') {
                    continue;
                }

                $summary['rows_published']++;
                $summary[$result['created'] ? 'published_created' : 'published_updated']++;
                $summary['published_row_ids'][] = $row->id;
            }

            $this->storePublicationProgress($batch, $summary);
        } while ($maxRuntimeSeconds === null || microtime(true) - $startedAt < $maxRuntimeSeconds);

        return [
            'complete' => false,
            'summary' => $summary,
        ];
    }

    protected function completeBatchPublication(LegacyStaffImportBatch $batch, User $user, array $summary): array
    {
        LegacyStaffImportPublication::query()->create([
            'batch_id' => $batch->id,
            'published_by' => $user->id,
            'published_at' => now(),
            'summary' => $summary,
        ]);

        $remainingRows = LegacyStaffImportRow::query()
            ->where('batch_id', $batch->id)
            ->whereNull('published_staff_id')
            ->count();

        $batchSummary = $batch->summary ?? [];
        unset($batchSummary[self::PUBLICATION_PROGRESS_KEY]);
        unset($batchSummary['publication_failure']);

        $batch->forceFill([
            'status' => $remainingRows === 0 ? 'published' : 'partially_published',
            'summary' => $batchSummary,
        ])->save();

        $this->auditLogService->log(
            'legacy_staff_import.batch.published',
            $batch,
            [],
            $summary,
            [
                'user_id' => $user->id,
            ],
        );

        return $summary;
    }

    protected function publicationProgressSummary(LegacyStaffImportBatch $batch, Builder $rowsQuery): array
    {
        $summary = ($batch->summary ?? [])[self::PUBLICATION_PROGRESS_KEY] ?? null;

        if (is_array($summary)) {
            return $summary;
        }

        return [
            'rows_considered' => (clone $rowsQuery)->count(),
            'rows_published' => 0,
            'published_created' => 0,
            'published_updated' => 0,
            'skipped_blocking_errors' => (clone $rowsQuery)
                ->whereNull('published_staff_id')
                ->whereHas('errors', fn (Builder $query) => $query->where('severity', 'error')->whereNull('resolved_at'))
                ->count(),
            'skipped_already_published' => (clone $rowsQuery)->whereNotNull('published_staff_id')->count(),
            'published_row_ids' => [],
        ];
    }

    protected function storePublicationProgress(LegacyStaffImportBatch $batch, array $summary): void
    {
        $batchSummary = $batch->fresh()->summary ?? [];
        $batchSummary[self::PUBLICATION_PROGRESS_KEY] = $summary;

        $batch->forceFill([
            'summary' => $batchSummary,
        ])->save();
    }

    public function publishRow(
        LegacyStaffImportRow $row,
        User $user,
        bool $writeAudit = true,
        bool $ensureApproved = true,
    ): array
    {
        if ($ensureApproved) {
            $this->ensureBatchIsApproved($row->batch()->with('approvalWorkflow')->firstOrFail());
        }

        return DB::transaction(function () use ($row, $user, $writeAudit): array {
            $row = LegacyStaffImportRow::query()
                ->with('matchedStaff')
                ->lockForUpdate()
                ->findOrFail($row->id);

            if ($row->published_staff_id) {
                return [
                    'status' => 'skipped_already_published',
                    'created' => false,
                    'staff_id' => $row->published_staff_id,
                ];
            }

            $hasBlockingErrors = $row->errors()
                ->where('severity', 'error')
                ->whereNull('resolved_at')
                ->exists();

            if ($hasBlockingErrors) {
                return [
                    'status' => 'skipped_blocking_errors',
                    'created' => false,
                    'staff_id' => null,
                ];
            }

            $published = $this->staffPublicationService->publish(
                $row->normalized_payload ?? [],
                $row->matchedStaff,
            );

            $row->forceFill([
                'status' => 'published',
                'published_staff_id' => $published['staff']->id,
            ])->save();

            if ($writeAudit) {
                $this->auditLogService->log(
                    'legacy_staff_import.row.published',
                    $row,
                    [],
                    $row->fresh()?->toArray() ?? $row->toArray(),
                    [
                        'user_id' => $user->id,
                        'published_staff_id' => $published['staff']->id,
                        'created_staff' => $published['created'],
                    ],
                );
            }

            return [
                'status' => 'published',
                'created' => $published['created'],
                'staff_id' => $published['staff']->id,
            ];
        }, 5);
    }

    protected function ensureBatchIsApproved(LegacyStaffImportBatch $batch): void
    {
        if (($batch->approvalWorkflow?->status ?? null) !== 'approved') {
            throw new \InvalidArgumentException('This batch must be approved before publication can begin.');
        }
    }
}
