<?php

namespace App\Http\Resources;

use App\Domain\Workplan\Models\Workplan;
use App\Enums\WorkplanStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkplanDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $workflow = $this->approvalWorkflow;
        $historicalActorIds = collect(data_get($workflow?->metadata, 'history', []))->pluck('steps')->flatten(1)->pluck('acted_by')->filter()->unique();
        $historicalActors = User::query()->whereKey($historicalActorIds)->get()->keyBy('id');
        $history = collect(data_get($workflow?->metadata, 'history', []))->map(fn (array $cycle): array => [
            'status' => $cycle['status'] ?? null,
            'submitted_at' => $cycle['submitted_at'] ?? null,
            'recorded_at' => $cycle['recorded_at'] ?? null,
            'steps' => collect($cycle['steps'] ?? [])->map(fn (array $step): array => $this->step($step, $historicalActors->get($step['acted_by'] ?? null)))->values()->all(),
        ])->values()->all();
        $currentSteps = $workflow?->steps->map(fn ($step): array => $this->step($step->toArray(), $step->actedBy))->values()->all() ?? [];
        $allSteps = collect($history)->pluck('steps')->flatten(1)->concat($currentSteps);
        $latestReturn = $allSteps->filter(fn (array $step): bool => $step['status'] === 'returned')->last();
        $latestRejection = $allSteps->filter(fn (array $step): bool => $step['status'] === 'rejected')->last();
        $pending = $workflow?->steps->firstWhere('status', 'pending');
        $canStepAct = $user && $workflow && in_array($workflow->status, ['submitted', 'under_review'], true)
            && $user->canAccessMda($this->mda_id) && $pending?->isActionableBy($user);
        $canMda = $user?->canAccessMda($this->mda_id) ?? false;

        return [
            'id' => $this->id, 'mda' => $this->mda?->only(['id','code','name']), 'year' => $this->year,
            'revision_no' => $this->revision_no, 'title' => $this->title, 'description' => $this->description,
            'status' => $this->status?->value, 'amendment_reason' => $this->amendment_reason,
            'prepared_by' => $this->person($this->preparedBy), 'submitted_by' => $this->person($this->submittedBy), 'submitted_at' => $this->submitted_at?->toISOString(),
            'approved_by' => $this->person($this->approvedBy), 'approved_at' => $this->approved_at?->toISOString(),
            'activated_by' => $this->person($this->activatedBy), 'activated_at' => $this->activated_at?->toISOString(),
            'closed_by' => $this->person($this->closedBy), 'closed_at' => $this->closed_at?->toISOString(),
            'supersedes_workplan_id' => $this->supersedes_workplan_id, 'superseded_by_workplan_id' => $this->superseded_by_workplan_id,
            'superseded_at' => $this->superseded_at?->toISOString(),
            'workflow' => $workflow ? ['id' => $workflow->id, 'status' => $workflow->status, 'current_step' => $pending ? $this->step($pending->toArray(), $pending->actedBy) : null, 'steps' => $currentSteps, 'history' => $history] : null,
            'latest_return_reason' => $latestReturn['comment'] ?? null, 'latest_rejection_reason' => $latestRejection['comment'] ?? $workflow?->rejection_comment,
            'revisions' => Workplan::query()->where('mda_id', $this->mda_id)->where('year', $this->year)->latest('revision_no')->get()->map(fn (Workplan $plan): array => [
                'id'=>$plan->id, 'revision_no'=>$plan->revision_no, 'status'=>$plan->status?->value, 'amendment_reason'=>$plan->amendment_reason,
                'supersedes_workplan_id'=>$plan->supersedes_workplan_id, 'superseded_by_workplan_id'=>$plan->superseded_by_workplan_id,
                'submitted_at'=>$plan->submitted_at?->toISOString(), 'approved_at'=>$plan->approved_at?->toISOString(), 'activated_at'=>$plan->activated_at?->toISOString(), 'superseded_at'=>$plan->superseded_at?->toISOString(), 'closed_at'=>$plan->closed_at?->toISOString(),
            ])->all(),
            'objectives' => $this->objectives->map(fn ($objective) => ['id'=>$objective->id,'department'=>$objective->department?->only(['id','code','name']),'code'=>$objective->code,'title'=>$objective->title,'description'=>$objective->description,'priority'=>$objective->priority,'performance_weight'=>$objective->performance_weight,'sort_order'=>$objective->sort_order,'activities'=>$objective->activities->map(fn ($activity) => ['id'=>$activity->id,'workplan_objective_id'=>$objective->id,'department'=>$activity->department?->only(['id','code','name']),'activity_code'=>$activity->activity_code,'title'=>$activity->title,'description'=>$activity->description,'expected_output'=>$activity->expected_output,'responsible_staff'=>$activity->responsibleStaff ? ['id'=>$activity->responsibleStaff->id,'staff_number'=>$activity->responsibleStaff->staff_number,'full_name'=>$activity->responsibleStaff->full_name] : null,'supporting_staff'=>$activity->supportAssignments->map(fn ($assignment) => ['id'=>$assignment->staff?->id,'staff_number'=>$assignment->staff?->staff_number,'full_name'=>$assignment->staff?->full_name]),'start_date'=>$activity->start_date?->toDateString(),'end_date'=>$activity->end_date?->toDateString(),'planned_cost'=>$activity->planned_cost,'funding_source'=>$activity->funding_source,'status'=>$activity->status,'performance_weight'=>$activity->performance_weight,'remarks'=>$activity->remarks,'sort_order'=>$activity->sort_order,'indicators'=>$activity->indicators->map(fn ($indicator) => ['id'=>$indicator->id,'code'=>$indicator->code,'indicator'=>$indicator->indicator,'unit'=>$indicator->unit,'baseline_value'=>$indicator->baseline_value,'annual_target_value'=>$indicator->annual_target_value,'target_mode'=>$indicator->target_mode?->value,'direction'=>$indicator->direction?->value,'weight'=>$indicator->weight,'sort_order'=>$indicator->sort_order,'is_required'=>$indicator->is_required,'targets'=>$indicator->targets->map(fn ($target) => ['id'=>$target->id,'period'=>$target->period?->value,'target_value'=>$target->target_value])])])]),
            'can' => [
                'edit' => $user?->can('update', $this->resource) ?? false,
                'submit' => $canMda && in_array($this->status, [WorkplanStatus::DRAFT, WorkplanStatus::RETURNED], true) && $user?->can('submit-workplans'),
                'approve' => (bool) $canStepAct, 'return' => (bool) $canStepAct, 'reject' => (bool) $canStepAct,
                'activate' => $canMda && $this->status === WorkplanStatus::APPROVED && $workflow?->status === 'approved' && $user?->can('approve-workplans'),
                'close' => $canMda && $this->status === WorkplanStatus::ACTIVE && $user?->can('approve-workplans'),
                'amend' => $canMda && $this->status === WorkplanStatus::ACTIVE && $user?->can('amend-workplans'),
                'update' => $user?->can('update', $this->resource) ?? false, 'create_objective' => $user?->can('update', $this->resource) ?? false,
            ],
        ];
    }

    private function person(?User $user): ?array { return $user ? ['id' => $user->id, 'name' => $user->name] : null; }
    private function step(array $step, ?User $actor = null): array { return ['step_no'=>$step['step_no'] ?? null,'status'=>$step['status'] ?? null,'reviewer_role'=>$step['reviewer_role'] ?? null,'reviewer_permission'=>data_get($step, 'metadata.required_permission'),'acted_at'=>$step['acted_at'] ?? null,'comment'=>$step['comment'] ?? null,'acted_by'=>$actor ? $this->person($actor) : (($step['acted_by'] ?? null) ? ['id'=>(int) $step['acted_by'], 'name'=>null] : null)]; }
}
