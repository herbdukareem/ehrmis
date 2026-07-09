<?php

namespace App\Domain\Movement\Services;

use App\Domain\Approval\Services\ApprovalWorkflowService;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\AuditLogService;
use InvalidArgumentException;

class MovementWorkbookWorkflowService
{
    protected const WORKFLOW_TYPE = 'movement_workbook_approval';

    public function __construct(
        protected ApprovalWorkflowService $approvalWorkflowService,
        protected AuditLogService $auditLogService,
    ) {
    }

    public function markReviewed(MovementWorkbook $workbook, ?User $actor = null): MovementWorkbook
    {
        if (! in_array($workbook->status, ['draft', 'reopened', 'rejected'], true)) {
            throw new InvalidArgumentException('Only draft or reopened workbooks can move to reviewed status.');
        }

        return DB::transaction(function () use ($workbook, $actor): MovementWorkbook {
            $before = $workbook->load('approvalWorkflow.steps')->toArray();

            if ($actor) {
                $this->approvalWorkflowService->submit(
                    $workbook,
                    self::WORKFLOW_TYPE,
                    $actor,
                    [[
                        'reviewer_role' => 'Approval Officer',
                        'metadata' => ['required_permission' => 'approve-movement-sheets'],
                    ]],
                    ['mda_id' => $workbook->mda_id, 'year' => $workbook->year],
                );
            }

            $workbook->forceFill([
                'status' => 'reviewed',
                'reviewed_by' => $actor?->id,
                'reviewed_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'locked_at' => null,
            ])->save();

            $this->auditLogService->logUpdated($workbook, $before, ['source' => 'movement_workflow.reviewed']);

            return $workbook->fresh(['lines', 'summaries', 'approvalWorkflow.steps']);
        });
    }

    public function approve(MovementWorkbook $workbook, ?User $actor = null): MovementWorkbook
    {
        if (! in_array($workbook->status, ['draft', 'reviewed', 'reopened', 'rejected'], true)) {
            throw new InvalidArgumentException('Only draft, reviewed, or reopened workbooks can be approved.');
        }

        return DB::transaction(function () use ($workbook, $actor): MovementWorkbook {
            $before = $workbook->load('approvalWorkflow.steps')->toArray();

            if ($actor && ! $workbook->approvalWorkflow) {
                $this->approvalWorkflowService->submit(
                    $workbook,
                    self::WORKFLOW_TYPE,
                    $actor,
                    [[
                        'reviewer_role' => 'Approval Officer',
                        'metadata' => ['required_permission' => 'approve-movement-sheets'],
                    ]],
                    ['mda_id' => $workbook->mda_id, 'year' => $workbook->year],
                );
                $workbook->refresh();
            }

            if ($actor && $workbook->approvalWorkflow && in_array($workbook->approvalWorkflow->status, ['submitted', 'under_review'], true)) {
                $this->approvalWorkflowService->approveStep($workbook->approvalWorkflow, $actor);
            }

            $workbook->forceFill([
                'status' => 'approved',
                'approved_by' => $actor?->id,
                'approved_at' => now(),
            ])->save();

            $this->auditLogService->logUpdated($workbook, $before, ['source' => 'movement_workflow.approved']);

            return $workbook->fresh(['lines', 'summaries', 'approvalWorkflow.steps']);
        });
    }

    public function reject(MovementWorkbook $workbook, User $actor, string $comment): MovementWorkbook
    {
        if (($workbook->approvalWorkflow?->status ?? null) === null) {
            throw new InvalidArgumentException('This movement workbook has not been submitted for approval.');
        }

        if (! in_array($workbook->status, ['reviewed', 'approved'], true)) {
            throw new InvalidArgumentException('Only reviewed or approved workbooks can be rejected.');
        }

        return DB::transaction(function () use ($workbook, $actor, $comment): MovementWorkbook {
            $before = $workbook->load('approvalWorkflow.steps')->toArray();

            $this->approvalWorkflowService->reject($workbook->approvalWorkflow, $actor, $comment);

            $workbook->forceFill([
                'status' => 'rejected',
                'approved_by' => null,
                'approved_at' => null,
                'locked_at' => null,
            ])->save();

            $this->auditLogService->logUpdated($workbook, $before, ['source' => 'movement_workflow.rejected']);

            return $workbook->fresh(['lines', 'summaries', 'approvalWorkflow.steps']);
        });
    }

    public function lock(MovementWorkbook $workbook): MovementWorkbook
    {
        if ($workbook->status !== 'approved') {
            throw new InvalidArgumentException('Only approved workbooks can be locked.');
        }

        return DB::transaction(function () use ($workbook): MovementWorkbook {
            $before = $workbook->load('approvalWorkflow.steps')->toArray();

            if ($workbook->approvalWorkflow && $workbook->approvalWorkflow->status === 'approved') {
                $this->approvalWorkflowService->lockSubject($workbook->approvalWorkflow);
            }

            $workbook->forceFill([
                'status' => 'locked',
                'locked_at' => now(),
            ])->save();

            $this->auditLogService->logUpdated($workbook, $before, ['source' => 'movement_workflow.locked']);

            return $workbook->fresh(['lines', 'summaries', 'approvalWorkflow.steps']);
        });
    }

    public function reopen(MovementWorkbook $workbook): MovementWorkbook
    {
        if (! in_array($workbook->status, ['reviewed', 'approved', 'locked', 'rejected'], true)) {
            throw new InvalidArgumentException('Only reviewed, approved, or locked workbooks can be reopened.');
        }

        return DB::transaction(function () use ($workbook): MovementWorkbook {
            $before = $workbook->load('approvalWorkflow.steps')->toArray();

            if ($workbook->approvalWorkflow) {
                $workbook->approvalWorkflow->steps()->delete();
                $workbook->approvalWorkflow->forceFill([
                    'status' => 'draft',
                    'submitted_by' => null,
                    'submitted_at' => null,
                    'approved_by' => null,
                    'approved_at' => null,
                    'rejected_by' => null,
                    'rejected_at' => null,
                    'rejection_comment' => null,
                    'current_step_no' => null,
                ])->save();
            }

            $workbook->forceFill([
                'status' => 'reopened',
                'locked_at' => null,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->auditLogService->logUpdated($workbook, $before, ['source' => 'movement_workflow.reopened']);

            return $workbook->fresh(['lines', 'summaries', 'approvalWorkflow.steps']);
        });
    }
}
