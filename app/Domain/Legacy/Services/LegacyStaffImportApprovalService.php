<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Approval\Models\ApprovalWorkflow;
use App\Domain\Approval\Services\ApprovalWorkflowService;
use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Models\User;
use App\Services\AuditLogService;
use InvalidArgumentException;

class LegacyStaffImportApprovalService
{
    public const WORKFLOW_TYPE = 'legacy_staff_import_publication';

    public function __construct(
        protected ApprovalWorkflowService $approvalWorkflowService,
        protected LegacyStaffImportQueryService $queryService,
        protected AuditLogService $auditLogService,
    ) {
    }

    public function submitBatch(LegacyStaffImportBatch $batch, User $user): ApprovalWorkflow
    {
        if ($batch->publications()->exists()) {
            throw new InvalidArgumentException('Published batches cannot be resubmitted for approval.');
        }

        $workflow = $batch->approvalWorkflow;

        if ($workflow && in_array($workflow->status, ['submitted', 'under_review', 'approved', 'locked'], true)) {
            throw new InvalidArgumentException('This batch already has an active approval workflow.');
        }

        $summary = $this->queryService->summarizeBatch($batch, $user);

        if (($summary['errors_count'] ?? 0) > 0) {
            throw new InvalidArgumentException('Resolve blocking import errors before submitting this batch for approval.');
        }

        if (($summary['rows_publishable'] ?? 0) < 1) {
            throw new InvalidArgumentException('This batch does not contain any publishable rows yet.');
        }

        $workflow = $this->approvalWorkflowService->submit(
            $batch,
            self::WORKFLOW_TYPE,
            $user,
            [
                ['step_no' => 1, 'reviewer_role' => 'Approval Officer'],
            ],
            [
                'rows_publishable' => $summary['rows_publishable'],
                'warnings_count' => $summary['warnings_count'] ?? 0,
            ],
        );

        $this->syncBatchStatus($batch, $workflow->status);

        $this->auditLogService->log(
            'legacy_staff_import.batch.submitted_for_approval',
            $batch,
            [],
            $batch->fresh()?->toArray() ?? $batch->toArray(),
            [
                'user_id' => $user->id,
                'workflow_id' => $workflow->id,
            ],
        );

        return $workflow;
    }

    public function approveBatch(LegacyStaffImportBatch $batch, User $user, ?string $comment = null): ApprovalWorkflow
    {
        $workflow = $batch->approvalWorkflow;

        if (! $workflow) {
            throw new InvalidArgumentException('No approval workflow exists for this batch.');
        }

        $workflow = $this->approvalWorkflowService->approveStep($workflow, $user, $comment);
        $this->syncBatchStatus($batch, $workflow->status);

        return $workflow;
    }

    public function rejectBatch(LegacyStaffImportBatch $batch, User $user, string $comment): ApprovalWorkflow
    {
        $workflow = $batch->approvalWorkflow;

        if (! $workflow) {
            throw new InvalidArgumentException('No approval workflow exists for this batch.');
        }

        $workflow = $this->approvalWorkflowService->reject($workflow, $user, $comment);
        $this->syncBatchStatus($batch, $workflow->status);

        return $workflow;
    }

    protected function syncBatchStatus(LegacyStaffImportBatch $batch, string $workflowStatus): void
    {
        $batch->forceFill([
            'status' => $workflowStatus,
        ])->save();
    }
}
