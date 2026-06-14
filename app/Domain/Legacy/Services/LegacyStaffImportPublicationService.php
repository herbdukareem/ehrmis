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
    public function __construct(
        protected StaffPublicationService $staffPublicationService,
        protected AuditLogService $auditLogService,
    ) {
    }

    public function publishBatch(LegacyStaffImportBatch $batch, User $user): array
    {
        $this->ensureBatchIsApproved($batch);

        return DB::transaction(function () use ($batch, $user): array {
            $summary = [
                'rows_considered' => 0,
                'rows_published' => 0,
                'published_created' => 0,
                'published_updated' => 0,
                'skipped_blocking_errors' => 0,
                'skipped_already_published' => 0,
                'published_row_ids' => [],
            ];

            $rows = LegacyStaffImportRow::query()
                ->with(['errors', 'matchedStaff'])
                ->where('batch_id', $batch->id)
                ->when(! $user->hasGlobalMdaAccess(), fn (Builder $query) => $query->where('mda_id', $user->mda_id))
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $summary['rows_considered']++;

                $result = $this->publishRow($row, $user, false);

                if ($result['status'] === 'published') {
                    $summary['rows_published']++;
                    $summary[$result['created'] ? 'published_created' : 'published_updated']++;
                    $summary['published_row_ids'][] = $row->id;
                } elseif ($result['status'] === 'skipped_blocking_errors') {
                    $summary['skipped_blocking_errors']++;
                } elseif ($result['status'] === 'skipped_already_published') {
                    $summary['skipped_already_published']++;
                }
            }

            LegacyStaffImportPublication::query()->create([
                'batch_id' => $batch->id,
                'published_by' => $user->id,
                'published_at' => now(),
                'summary' => $summary,
            ]);

            $batch->forceFill([
                'status' => $summary['rows_published'] > 0 ? 'partially_published' : $batch->status,
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
        });
    }

    public function publishRow(LegacyStaffImportRow $row, User $user, bool $writeAudit = true): array
    {
        $this->ensureBatchIsApproved($row->batch()->with('approvalWorkflow')->firstOrFail());

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

        return DB::transaction(function () use ($row, $user, $writeAudit): array {
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
        });
    }

    protected function ensureBatchIsApproved(LegacyStaffImportBatch $batch): void
    {
        if (($batch->approvalWorkflow?->status ?? null) !== 'approved') {
            throw new \InvalidArgumentException('This batch must be approved before publication can begin.');
        }
    }
}
