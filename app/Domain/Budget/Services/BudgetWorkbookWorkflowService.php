<?php

namespace App\Domain\Budget\Services;

use App\Domain\Approval\Services\ApprovalWorkflowService;
use App\Domain\Budget\Models\BudgetWorkbook;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BudgetWorkbookWorkflowService
{
    protected const WORKFLOW_TYPE = 'budget_workbook_approval';

    public function __construct(
        protected ApprovalWorkflowService $approvalWorkflowService,
        protected AuditLogService $auditLogService,
    ) {
    }

    public function submit(BudgetWorkbook $workbook, ?User $actor = null): BudgetWorkbook
    {
        if (! in_array($workbook->status, ['draft', 'reopened', 'rejected'], true)) {
            throw new InvalidArgumentException('Only draft, reopened, or rejected budget workbooks can be submitted.');
        }

        return DB::transaction(function () use ($workbook, $actor): BudgetWorkbook {
            $before = $workbook->load('approvalWorkflow.steps')->toArray();

            if ($actor) {
                $this->approvalWorkflowService->submit(
                    $workbook,
                    self::WORKFLOW_TYPE,
                    $actor,
                    [[
                        'reviewer_role' => 'Approval Officer',
                        'metadata' => ['required_permission' => 'approve-budgets'],
                    ]],
                    ['mda_id' => $workbook->mda_id, 'year' => $workbook->year],
                );
            }

            $workbook->forceFill([
                'status' => 'submitted',
                'approved_by' => null,
                'approved_at' => null,
                'locked_at' => null,
            ])->save();

            $this->auditLogService->logUpdated($workbook, $before, ['source' => 'budget_workflow.submitted']);

            return $workbook->fresh(['lines', 'movementWorkbook', 'mda', 'approvalWorkflow.steps']);
        });
    }

    public function approve(BudgetWorkbook $workbook, ?User $actor = null): BudgetWorkbook
    {
        if (! in_array($workbook->status, ['draft', 'submitted', 'reopened', 'rejected'], true)) {
            throw new InvalidArgumentException('Only draft, submitted, reopened, or rejected budget workbooks can be approved.');
        }

        return DB::transaction(function () use ($workbook, $actor): BudgetWorkbook {
            $before = $workbook->load('approvalWorkflow.steps')->toArray();

            if ($actor && ! $workbook->approvalWorkflow) {
                $this->approvalWorkflowService->submit(
                    $workbook,
                    self::WORKFLOW_TYPE,
                    $actor,
                    [[
                        'reviewer_role' => 'Approval Officer',
                        'metadata' => ['required_permission' => 'approve-budgets'],
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

            $this->auditLogService->logUpdated($workbook, $before, ['source' => 'budget_workflow.approved']);

            return $workbook->fresh(['lines', 'movementWorkbook', 'mda', 'approvalWorkflow.steps']);
        });
    }

    public function reject(BudgetWorkbook $workbook, User $actor, string $comment): BudgetWorkbook
    {
        if (($workbook->approvalWorkflow?->status ?? null) === null) {
            throw new InvalidArgumentException('This budget workbook has not been submitted for approval.');
        }

        if (! in_array($workbook->status, ['submitted', 'approved'], true)) {
            throw new InvalidArgumentException('Only submitted or approved budget workbooks can be rejected.');
        }

        return DB::transaction(function () use ($workbook, $actor, $comment): BudgetWorkbook {
            $before = $workbook->load('approvalWorkflow.steps')->toArray();

            $this->approvalWorkflowService->reject($workbook->approvalWorkflow, $actor, $comment);

            $workbook->forceFill([
                'status' => 'rejected',
                'approved_by' => null,
                'approved_at' => null,
                'locked_at' => null,
            ])->save();

            $this->auditLogService->logUpdated($workbook, $before, ['source' => 'budget_workflow.rejected']);

            return $workbook->fresh(['lines', 'movementWorkbook', 'mda', 'approvalWorkflow.steps']);
        });
    }

    public function lock(BudgetWorkbook $workbook): BudgetWorkbook
    {
        if ($workbook->status !== 'approved') {
            throw new InvalidArgumentException('Only approved budget workbooks can be locked.');
        }

        return DB::transaction(function () use ($workbook): BudgetWorkbook {
            $before = $workbook->load('approvalWorkflow.steps')->toArray();

            if ($workbook->approvalWorkflow && $workbook->approvalWorkflow->status === 'approved') {
                $this->approvalWorkflowService->lockSubject($workbook->approvalWorkflow);
            }

            $workbook->forceFill([
                'status' => 'locked',
                'locked_at' => now(),
            ])->save();

            $this->auditLogService->logUpdated($workbook, $before, ['source' => 'budget_workflow.locked']);

            return $workbook->fresh(['lines', 'movementWorkbook', 'mda', 'approvalWorkflow.steps']);
        });
    }

    public function reopen(BudgetWorkbook $workbook): BudgetWorkbook
    {
        if (! in_array($workbook->status, ['submitted', 'approved', 'locked', 'rejected'], true)) {
            throw new InvalidArgumentException('Only submitted, approved, locked, or rejected budget workbooks can be reopened.');
        }

        return DB::transaction(function () use ($workbook): BudgetWorkbook {
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
                'approved_by' => null,
                'approved_at' => null,
                'locked_at' => null,
            ])->save();

            $this->auditLogService->logUpdated($workbook, $before, ['source' => 'budget_workflow.reopened']);

            return $workbook->fresh(['lines', 'movementWorkbook', 'mda', 'approvalWorkflow.steps']);
        });
    }
}
