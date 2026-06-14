<?php

namespace App\Domain\Approval\Services;

use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\Approval\Models\ApprovalWorkflow;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApprovalWorkflowService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {
    }

    public function submit(
        Model $subject,
        string $workflowType,
        User $submittedBy,
        array $steps,
        array $metadata = [],
    ): ApprovalWorkflow {
        if ($steps === []) {
            throw new InvalidArgumentException('At least one approval step is required.');
        }

        return DB::transaction(function () use ($subject, $workflowType, $submittedBy, $steps, $metadata): ApprovalWorkflow {
            $workflow = ApprovalWorkflow::query()->firstOrNew([
                'workflow_type' => $workflowType,
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
            ]);

            if ($workflow->exists && ! in_array($workflow->status, ['draft', 'rejected'], true)) {
                throw new InvalidArgumentException('This workflow cannot be submitted in its current state.');
            }

            $before = $workflow->exists ? $workflow->load('steps')->toArray() : [];

            $normalizedSteps = collect($steps)
                ->values()
                ->map(fn (array $step, int $index): array => [
                    'step_no' => (int) ($step['step_no'] ?? ($index + 1)),
                    'reviewer_user_id' => $step['reviewer_user_id'] ?? null,
                    'reviewer_role' => $step['reviewer_role'] ?? null,
                    'metadata' => $step['metadata'] ?? null,
                ])
                ->sortBy('step_no')
                ->values();

            $workflow->fill([
                'status' => 'submitted',
                'submitted_by' => $submittedBy->id,
                'submitted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_comment' => null,
                'current_step_no' => $normalizedSteps->first()['step_no'],
                'metadata' => $metadata,
            ]);
            $workflow->save();

            $workflow->steps()->delete();

            foreach ($normalizedSteps as $step) {
                $workflow->steps()->create([
                    'step_no' => $step['step_no'],
                    'reviewer_user_id' => $step['reviewer_user_id'],
                    'reviewer_role' => $step['reviewer_role'],
                    'status' => 'pending',
                    'metadata' => $step['metadata'],
                ]);
            }

            $eventCode = $before === [] ? 'approval_workflow.submitted' : 'approval_workflow.resubmitted';

            $this->auditLogService->log(
                $eventCode,
                $workflow,
                $before,
                $workflow->fresh('steps')?->toArray() ?? $workflow->toArray(),
                [
                    'workflow_type' => $workflowType,
                    'subject_type' => $subject::class,
                    'subject_id' => $subject->getKey(),
                    'submitted_by' => $submittedBy->id,
                ],
            );

            return $workflow->fresh('steps');
        });
    }

    public function approveStep(ApprovalWorkflow $workflow, User $actor, ?string $comment = null): ApprovalWorkflow
    {
        if (! in_array($workflow->status, ['submitted', 'under_review'], true)) {
            throw new InvalidArgumentException('Only submitted workflows can be approved.');
        }

        return DB::transaction(function () use ($workflow, $actor, $comment): ApprovalWorkflow {
            $workflow = $workflow->fresh('steps');
            $before = $workflow?->toArray() ?? [];
            $step = $this->resolvePendingStep($workflow, $actor);

            $step->forceFill([
                'status' => 'approved',
                'comment' => $comment,
                'acted_at' => now(),
                'acted_by' => $actor->id,
            ])->save();

            $nextStep = $workflow->steps()->where('status', 'pending')->orderBy('step_no')->first();

            $workflow->forceFill([
                'status' => $nextStep ? 'under_review' : 'approved',
                'current_step_no' => $nextStep?->step_no ?? $step->step_no,
                'approved_by' => $nextStep ? null : $actor->id,
                'approved_at' => $nextStep ? null : now(),
            ])->save();

            $this->auditLogService->log(
                'approval_workflow.step_approved',
                $workflow,
                $before,
                $workflow->fresh('steps')?->toArray() ?? $workflow->toArray(),
                [
                    'step_no' => $step->step_no,
                    'acted_by' => $actor->id,
                ],
            );

            return $workflow->fresh('steps');
        });
    }

    public function reject(ApprovalWorkflow $workflow, User $actor, string $comment): ApprovalWorkflow
    {
        if (! in_array($workflow->status, ['submitted', 'under_review'], true)) {
            throw new InvalidArgumentException('Only submitted workflows can be rejected.');
        }

        return DB::transaction(function () use ($workflow, $actor, $comment): ApprovalWorkflow {
            $workflow = $workflow->fresh('steps');
            $before = $workflow?->toArray() ?? [];
            $step = $this->resolvePendingStep($workflow, $actor);

            $step->forceFill([
                'status' => 'rejected',
                'comment' => $comment,
                'acted_at' => now(),
                'acted_by' => $actor->id,
            ])->save();

            $workflow->forceFill([
                'status' => 'rejected',
                'current_step_no' => $step->step_no,
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_comment' => $comment,
            ])->save();

            $this->auditLogService->log(
                'approval_workflow.rejected',
                $workflow,
                $before,
                $workflow->fresh('steps')?->toArray() ?? $workflow->toArray(),
                [
                    'step_no' => $step->step_no,
                    'acted_by' => $actor->id,
                ],
            );

            return $workflow->fresh('steps');
        });
    }

    public function lockSubject(ApprovalWorkflow $workflow): ApprovalWorkflow
    {
        if ($workflow->status !== 'approved') {
            throw new InvalidArgumentException('Only approved workflows can be locked.');
        }

        $before = $workflow->load('steps')->toArray();

        $workflow->forceFill([
            'status' => 'locked',
        ])->save();

        $this->auditLogService->log(
            'approval_workflow.locked',
            $workflow,
            $before,
            $workflow->fresh('steps')?->toArray() ?? $workflow->toArray(),
        );

        return $workflow->fresh('steps');
    }

    protected function resolvePendingStep(ApprovalWorkflow $workflow, User $actor): ApprovalStep
    {
        $step = $workflow->steps()->where('status', 'pending')->orderBy('step_no')->first();

        if (! $step) {
            throw new InvalidArgumentException('No pending approval step was found.');
        }

        if (! $step->isActionableBy($actor)) {
            throw new AuthorizationException('You are not allowed to act on this approval step.');
        }

        return $step;
    }
}
