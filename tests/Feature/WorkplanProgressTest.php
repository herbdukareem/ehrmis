<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Domain\Workplan\Models\Workplan;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkplanProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_active_activity_creates_one_report_with_immutable_period_target_snapshot(): void
    {
        [$mda, $author] = $this->scopedUser(['update-workplan-progress', 'view-workplans']);
        $plan = $this->plan($mda, $author, 'active');
        $activity = $plan->objectives()->firstOrFail()->activities()->firstOrFail();

        $created = $this->actingAs($author)->postJson("/api/workplan-activities/{$activity->id}/progress-reports", ['period' => 'q2'])
            ->assertCreated()
            ->assertJsonPath('data.period', 'q2')
            ->assertJsonPath('data.status', 'draft');

        $reportId = $created->json('data.id');
        $this->assertDatabaseHas('workplan_indicator_progresses', [
            'workplan_progress_report_id' => $reportId,
            'target_value_snapshot' => 50,
        ]);

        $activity->indicators()->firstOrFail()->targets()->where('period', 'q2')->update(['target_value' => 75]);
        $this->actingAs($author)->postJson("/api/workplan-activities/{$activity->id}/progress-reports", ['period' => 'q2'])
            ->assertCreated()
            ->assertJsonPath('data.id', $reportId);
        $this->assertDatabaseCount('workplan_progress_reports', 1);
        $this->assertDatabaseHas('workplan_indicator_progresses', ['workplan_progress_report_id' => $reportId, 'target_value_snapshot' => 50]);
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'workplan.progress.created', 'auditable_id' => $reportId]);
    }

    public function test_progress_return_resubmission_and_verification_enforce_lifecycle_and_mda_scope(): void
    {
        [$mda, $author] = $this->scopedUser(['update-workplan-progress', 'view-workplans']);
        [, $verifier] = $this->scopedUser(['verify-workplan-progress', 'view-workplans'], $mda);
        [, $outsider] = $this->scopedUser(['update-workplan-progress', 'view-workplans']);
        $plan = $this->plan($mda, $author, 'active');
        $activity = $plan->objectives()->firstOrFail()->activities()->firstOrFail();
        $reportId = $this->actingAs($author)->postJson("/api/workplan-activities/{$activity->id}/progress-reports", ['period' => 'q1'])->assertCreated()->json('data.id');
        $indicatorId = $activity->indicators()->firstOrFail()->id;

        $this->actingAs($outsider)->putJson("/api/workplan-progress-reports/{$reportId}", ['achievement_summary' => 'No access'])->assertNotFound();
        $this->actingAs($author)->putJson("/api/workplan-progress-reports/{$reportId}/indicators", ['indicators' => [['workplan_indicator_id' => $indicatorId, 'actual_value' => 22]]])->assertOk();
        $this->actingAs($author)->postJson("/api/workplan-progress-reports/{$reportId}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->actingAs($author)->putJson("/api/workplan-progress-reports/{$reportId}", ['achievement_summary' => 'Locked'])->assertUnprocessable();
        $this->actingAs($verifier)->postJson("/api/workplan-progress-reports/{$reportId}/return", ['return_reason' => 'Attach the supervision note.'])->assertOk()->assertJsonPath('data.status', 'returned');
        $this->actingAs($author)->putJson("/api/workplan-progress-reports/{$reportId}", ['achievement_summary' => 'Note attached'])->assertOk();
        $this->actingAs($author)->postJson("/api/workplan-progress-reports/{$reportId}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->actingAs($verifier)->postJson("/api/workplan-progress-reports/{$reportId}/verify")->assertOk()->assertJsonPath('data.status', 'verified');
        $this->actingAs($author)->putJson("/api/workplan-progress-reports/{$reportId}/indicators", ['indicators' => [['workplan_indicator_id' => $indicatorId, 'actual_value' => 25]]])->assertUnprocessable();

        $this->assertDatabaseHas('audit_logs', ['event_code' => 'workplan.progress.returned', 'auditable_id' => $reportId]);
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'workplan.progress.resubmitted', 'auditable_id' => $reportId]);
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'workplan.progress.verified', 'auditable_id' => $reportId]);
    }

    public function test_non_active_revision_cannot_create_progress_and_active_revisions_remain_isolated(): void
    {
        [$mda, $author] = $this->scopedUser(['update-workplan-progress', 'view-workplans']);
        $draft = $this->plan($mda, $author, 'draft', 2027, 1);
        $this->actingAs($author)->postJson('/api/workplan-activities/'.$draft->objectives()->firstOrFail()->activities()->firstOrFail()->id.'/progress-reports', ['period' => 'q1'])->assertUnprocessable();

        $old = $this->plan($mda, $author, 'active', 2028, 1);
        $new = $this->plan($mda, $author, 'active', 2029, 2);
        $oldActivity = $old->objectives()->firstOrFail()->activities()->firstOrFail();
        $newActivity = $new->objectives()->firstOrFail()->activities()->firstOrFail();
        $oldId = $this->actingAs($author)->postJson("/api/workplan-activities/{$oldActivity->id}/progress-reports", ['period' => 'q1'])->assertCreated()->json('data.id');
        $newId = $this->actingAs($author)->postJson("/api/workplan-activities/{$newActivity->id}/progress-reports", ['period' => 'q1'])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('workplan_progress_reports', ['id' => $oldId, 'workplan_id' => $old->id, 'workplan_activity_id' => $oldActivity->id]);
        $this->assertDatabaseHas('workplan_progress_reports', ['id' => $newId, 'workplan_id' => $new->id, 'workplan_activity_id' => $newActivity->id]);
    }

    /** @return array{0: Mda, 1: User} */
    private function scopedUser(array $permissions, ?Mda $mda = null): array
    {
        $mda ??= Mda::factory()->create();
        $user = User::factory()->mdaUser($mda)->create();
        $user->givePermissionTo($permissions);
        UserAccessScope::query()->create(['user_id' => $user->id, 'scope_type' => 'mda', 'mda_id' => $mda->id]);
        return [$mda, $user];
    }

    private function plan(Mda $mda, User $author, string $status, int $year = 2027, int $revision = 1): Workplan
    {
        $department = Department::factory()->create(['mda_id' => $mda->id]);
        $staff = Staff::query()->create(['mda_id'=>$mda->id, 'staff_number'=>"WP-{$mda->id}-{$year}-{$revision}", 'surname'=>'Officer', 'first_name'=>'Plan', 'full_name'=>'Plan Officer', 'status'=>'active']);
        $staff->employments()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'is_current'=>true, 'employment_status'=>'active']);
        $plan = Workplan::query()->create(['mda_id'=>$mda->id, 'year'=>$year, 'revision_no'=>$revision, 'title'=>"Plan {$year}", 'status'=>$status, 'prepared_by'=>$author->id]);
        $objective = $plan->objectives()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'code'=>"OBJ-{$year}-{$revision}", 'title'=>'Objective', 'performance_weight'=>1]);
        $activity = $objective->activities()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'responsible_staff_id'=>$staff->id, 'activity_code'=>"ACT-{$year}-{$revision}", 'title'=>'Activity', 'start_date'=>"{$year}-01-01", 'end_date'=>"{$year}-12-31", 'planned_cost'=>100, 'funding_source'=>'Budget', 'performance_weight'=>1]);
        $indicator = $activity->indicators()->create(['mda_id'=>$mda->id, 'code'=>'KPI', 'indicator'=>'Delivery', 'unit'=>'facilities', 'annual_target_value'=>100, 'target_mode'=>'absolute', 'direction'=>'increase', 'weight'=>1, 'is_required'=>true]);
        foreach (['q1'=>25, 'q2'=>50, 'q3'=>75, 'q4'=>100, 'annual'=>100] as $period => $target) $indicator->targets()->create(['mda_id'=>$mda->id, 'period'=>$period, 'target_value'=>$target]);
        return $plan;
    }
}
