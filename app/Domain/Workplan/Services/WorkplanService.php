<?php

namespace App\Domain\Workplan\Services;

use App\Domain\Organization\Models\Department;
use App\Domain\Staff\Models\Staff;
use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanActivity;
use App\Domain\Workplan\Models\WorkplanIndicator;
use App\Domain\Workplan\Models\WorkplanObjective;
use App\Enums\WorkplanStatus;
use App\Enums\WorkplanTargetMode;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkplanService
{
    public function __construct(protected AuditLogService $audit) {}

    public function create(array $data, User $actor): Workplan
    {
        $this->assertMdaAccess($actor, (int) $data['mda_id']);
        if (Workplan::query()->where('mda_id', $data['mda_id'])->where('year', $data['year'])->where('revision_no', 1)->exists()) $this->fail('year', 'A revision 1 workplan already exists for this MDA and year.');
        return DB::transaction(function () use ($data, $actor): Workplan {
            $workplan = Workplan::query()->create(['mda_id' => $data['mda_id'], 'year' => $data['year'], 'revision_no' => 1, 'title' => $data['title'], 'description' => $data['description'] ?? null, 'status' => WorkplanStatus::DRAFT, 'prepared_by' => $actor->id]);
            $this->log('workplan.created', $workplan, [], $workplan->toArray(), ['source' => 'workplan']);
            return $workplan;
        });
    }

    public function update(Workplan $workplan, array $data, User $actor): Workplan
    {
        $this->ensureEditable($workplan);
        $before = $workplan->toArray();
        $workplan->fill(collect($data)->only(['title', 'description'])->all())->save();
        $this->log('workplan.updated', $workplan, $before, $workplan->fresh()->toArray(), ['source' => 'workplan']);
        return $workplan->fresh();
    }

    public function createObjective(Workplan $workplan, array $data): WorkplanObjective
    {
        $this->ensureEditable($workplan); $this->assertDepartment($data['department_id'] ?? null, $workplan->mda_id);
        if ($workplan->objectives()->where('code', $data['code'])->exists()) $this->fail('code', 'Objective code must be unique within the workplan.');
        return DB::transaction(function () use ($workplan, $data): WorkplanObjective {
            $objective = $workplan->objectives()->create([...collect($data)->only(['department_id','code','title','description','priority','performance_weight','sort_order'])->all(), 'mda_id' => $workplan->mda_id]);
            $this->log('workplan.objective.created', $objective, [], $objective->toArray(), $this->context($workplan, ['objective_id' => $objective->id, 'source' => 'workplan_authoring']));
            return $objective;
        });
    }

    public function updateObjective(WorkplanObjective $objective, array $data): WorkplanObjective
    {
        $workplan = $objective->workplan; $this->ensureEditable($workplan); $this->assertDepartment($data['department_id'] ?? $objective->department_id, $workplan->mda_id);
        $before = $objective->toArray(); $objective->fill(collect($data)->only(['department_id','code','title','description','priority','performance_weight','sort_order'])->all())->save();
        $this->log('workplan.objective.updated', $objective, $before, $objective->fresh()->toArray(), $this->context($workplan, ['objective_id' => $objective->id, 'source' => 'workplan_authoring'])); return $objective->fresh();
    }

    public function deleteObjective(WorkplanObjective $objective): void
    {
        $workplan = $objective->workplan; $this->ensureEditable($workplan); $before = $objective->toArray(); $objective->delete();
        $this->log('workplan.objective.deleted', WorkplanObjective::class, $before, [], $this->context($workplan, ['objective_id' => $objective->id, 'source' => 'workplan_authoring']));
    }

    public function createActivity(WorkplanObjective $objective, array $data): WorkplanActivity
    {
        $workplan = $objective->workplan; $this->ensureEditable($workplan); $this->validateActivity($workplan, $data);
        if ($objective->activities()->where('activity_code', $data['activity_code'])->exists()) $this->fail('activity_code', 'Activity code must be unique within the objective.');
        return DB::transaction(function () use ($objective, $data, $workplan): WorkplanActivity {
            $activity = $objective->activities()->create([...collect($data)->only(['department_id','responsible_staff_id','activity_code','title','description','expected_output','start_date','end_date','planned_cost','funding_source','status','performance_weight','remarks','sort_order'])->all(), 'mda_id' => $workplan->mda_id]);
            $this->log('workplan.activity.created', $activity, [], $activity->toArray(), $this->context($workplan, ['objective_id' => $objective->id, 'activity_id' => $activity->id, 'source' => 'workplan_authoring'])); return $activity;
        });
    }

    public function updateActivity(WorkplanActivity $activity, array $data): WorkplanActivity
    {
        $workplan = $activity->objective->workplan; $this->ensureEditable($workplan); $this->validateActivity($workplan, [...$activity->only(['department_id','responsible_staff_id','start_date','end_date']), ...$data]);
        $before = $activity->toArray(); $activity->fill(collect($data)->only(['department_id','responsible_staff_id','activity_code','title','description','expected_output','start_date','end_date','planned_cost','funding_source','status','performance_weight','remarks','sort_order'])->all())->save();
        if ($activity->responsible_staff_id) { $activity->supportAssignments()->where('staff_id', $activity->responsible_staff_id)->delete(); }
        $this->log('workplan.activity.updated', $activity, $before, $activity->fresh()->toArray(), $this->context($workplan, ['objective_id' => $activity->workplan_objective_id, 'activity_id' => $activity->id, 'source' => 'workplan_authoring'])); return $activity->fresh();
    }

    public function deleteActivity(WorkplanActivity $activity): void
    {
        $workplan = $activity->objective->workplan; $this->ensureEditable($workplan); $before = $activity->toArray(); $activity->delete();
        $this->log('workplan.activity.deleted', WorkplanActivity::class, $before, [], $this->context($workplan, ['activity_id' => $activity->id, 'source' => 'workplan_authoring']));
    }

    public function syncSupportingStaff(WorkplanActivity $activity, array $staffIds): WorkplanActivity
    {
        $workplan = $activity->objective->workplan; $this->ensureEditable($workplan); $staffIds = collect($staffIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($staffIds->contains((int) $activity->responsible_staff_id)) { $this->fail('staff_ids', 'The responsible officer cannot also be a supporting officer.'); }
        $staff = Staff::query()->whereIn('id', $staffIds)->get(); if ($staff->count() !== $staffIds->count() || $staff->contains(fn (Staff $row) => (int) $row->mda_id !== (int) $workplan->mda_id || $row->status !== 'active')) { $this->fail('staff_ids', 'All supporting staff must be active staff in the workplan MDA.'); }
        DB::transaction(function () use ($activity, $staffIds, $workplan): void { $before = $activity->supportAssignments()->get()->toArray(); $activity->supportAssignments()->delete(); foreach ($staffIds as $staffId) { $activity->supportAssignments()->create(['mda_id' => $workplan->mda_id, 'staff_id' => $staffId, 'role' => 'support']); } $this->log('workplan.assignment.synced', $activity, $before, $activity->supportAssignments()->get()->toArray(), $this->context($workplan, ['activity_id' => $activity->id, 'source' => 'workplan_authoring'])); }); return $activity->fresh('supportAssignments.staff');
    }

    public function createIndicator(WorkplanActivity $activity, array $data): WorkplanIndicator
    {
        $workplan = $activity->objective->workplan; $this->ensureEditable($workplan); $this->validateIndicator($data);
        if ($activity->indicators()->where('code', $data['code'])->exists()) $this->fail('code', 'Indicator code must be unique within the activity.');
        $indicator = $activity->indicators()->create([...collect($data)->only(['code','indicator','unit','baseline_value','annual_target_value','target_mode','direction','weight','sort_order','is_required'])->all(), 'mda_id' => $workplan->mda_id]);
        $this->log('workplan.indicator.created', $indicator, [], $indicator->toArray(), $this->context($workplan, ['activity_id' => $activity->id, 'indicator_id' => $indicator->id, 'source' => 'workplan_authoring'])); return $indicator;
    }

    public function updateIndicator(WorkplanIndicator $indicator, array $data): WorkplanIndicator
    {
        $workplan = $indicator->activity->objective->workplan; $this->ensureEditable($workplan); $this->validateIndicator([...$indicator->only(['target_mode','direction','weight','baseline_value','annual_target_value']), ...$data]);
        $before = $indicator->toArray(); $indicator->fill(collect($data)->only(['code','indicator','unit','baseline_value','annual_target_value','target_mode','direction','weight','sort_order','is_required'])->all())->save(); $this->log('workplan.indicator.updated', $indicator, $before, $indicator->fresh()->toArray(), $this->context($workplan, ['activity_id' => $indicator->workplan_activity_id, 'indicator_id' => $indicator->id, 'source' => 'workplan_authoring'])); return $indicator->fresh();
    }

    public function deleteIndicator(WorkplanIndicator $indicator): void
    {
        $workplan = $indicator->activity->objective->workplan; $this->ensureEditable($workplan); $before = $indicator->toArray(); $indicator->delete(); $this->log('workplan.indicator.deleted', WorkplanIndicator::class, $before, [], $this->context($workplan, ['indicator_id' => $indicator->id, 'source' => 'workplan_authoring']));
    }

    public function syncTargets(WorkplanIndicator $indicator, array $targets): WorkplanIndicator
    {
        $workplan = $indicator->activity->objective->workplan; $this->ensureEditable($workplan); $periods = collect($targets)->pluck('period'); if ($periods->unique()->count() !== $periods->count()) { $this->fail('targets', 'Each target period may only be supplied once.'); }
        if ($indicator->direction->value === 'increase') { $values = collect($targets)->filter(fn ($target) => $target['period'] !== 'annual' && $target['target_value'] !== null)->sortBy(fn ($target) => $target['period'])->pluck('target_value')->values(); if ($values->values()->all() !== $values->sort()->values()->all()) { $this->fail('targets', 'Increasing indicator targets must be cumulative.'); } }
        DB::transaction(function () use ($indicator, $targets, $workplan): void { $before = $indicator->targets()->get()->toArray(); $indicator->targets()->delete(); foreach ($targets as $target) { $indicator->targets()->create(['mda_id' => $workplan->mda_id, 'period' => $target['period'], 'target_value' => $target['target_value']]); } $this->log('workplan.indicator_targets.synced', $indicator, $before, $indicator->targets()->get()->toArray(), $this->context($workplan, ['indicator_id' => $indicator->id, 'source' => 'workplan_authoring'])); }); return $indicator->fresh('targets');
    }

    public function ensureEditable(Workplan $workplan): void { if (! $workplan->isEditable()) { throw ValidationException::withMessages(['workplan' => 'Only draft workplans may be structurally edited.']); } }
    protected function validateActivity(Workplan $workplan, array $data): void { $this->assertDepartment($data['department_id'] ?? null, $workplan->mda_id); if (($data['start_date'] ?? null) > ($data['end_date'] ?? null)) $this->fail('end_date', 'The end date must be on or after the start date.'); foreach (['start_date','end_date'] as $field) { if ((int) date('Y', strtotime($data[$field])) !== (int) $workplan->year) $this->fail($field, 'Activity dates must fall within the workplan year.'); } if (isset($data['planned_cost']) && $data['planned_cost'] !== null && (float) $data['planned_cost'] < 0) $this->fail('planned_cost', 'Planned cost cannot be negative.'); $this->assertResponsibleStaff($data['responsible_staff_id'] ?? null, $workplan->mda_id, $data['department_id'] ?? null); }
    protected function validateIndicator(array $data): void { if ((float) ($data['weight'] ?? 0) <= 0) $this->fail('weight', 'Indicator weight must be greater than zero.'); if (($data['target_mode'] ?? null) === WorkplanTargetMode::MILESTONE->value && ($data['direction'] ?? null) !== 'milestone') $this->fail('direction', 'Milestone indicators must use milestone direction.'); }
    protected function assertDepartment(?int $id, int $mdaId): void { if ($id !== null && ! Department::query()->whereKey($id)->where('mda_id', $mdaId)->exists()) $this->fail('department_id', 'The department must belong to the workplan MDA.'); }
    protected function assertResponsibleStaff(?int $id, int $mdaId, ?int $departmentId): void { if ($id === null) return; $staff = Staff::query()->with('currentEmployment')->find($id); if (! $staff || (int) $staff->mda_id !== $mdaId || $staff->status !== 'active') $this->fail('responsible_staff_id', 'The responsible staff member must be active and belong to the workplan MDA.'); if ($departmentId !== null && (int) $staff->currentEmployment?->department_id !== $departmentId) $this->fail('responsible_staff_id', 'The responsible staff member must currently belong to the activity department.'); }
    protected function assertMdaAccess(User $actor, int $mdaId): void { abort_unless($actor->canAccessMda($mdaId), 403); }
    protected function fail(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
    protected function context(Workplan $workplan, array $extra = []): array { return [...$extra, 'mda_id' => $workplan->mda_id, 'workplan_id' => $workplan->id, 'year' => $workplan->year, 'revision_no' => $workplan->revision_no]; }
    protected function log(string $event, mixed $auditable, array $before, array $after, array $context): void { $this->audit->log($event, $auditable, $before, $after, $context); }
}
