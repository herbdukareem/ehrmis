<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanIndicatorProgress;
use App\Domain\Workplan\Models\WorkplanProgressReport;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkplanPerformanceViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(RolesAndPermissionsSeeder::class); }

    public function test_authorized_user_gets_a_read_only_revision_specific_drill_down(): void
    {
        [$mda, $user] = $this->user('MDA Admin');
        $plan = $this->plan($mda);
        $activity = $plan->objectives()->firstOrFail()->activities()->firstOrFail();
        $indicator = $activity->indicators()->firstOrFail();
        $report = WorkplanProgressReport::query()->create(['mda_id'=>$mda->id, 'workplan_id'=>$plan->id, 'workplan_activity_id'=>$activity->id, 'period'=>'q2', 'status'=>'verified', 'reported_expenditure'=>25]);
        WorkplanIndicatorProgress::query()->create(['mda_id'=>$mda->id, 'workplan_progress_report_id'=>$report->id, 'workplan_indicator_id'=>$indicator->id, 'target_value_snapshot'=>50, 'actual_value'=>40]);

        $this->actingAs($user)->getJson("/api/workplans/{$plan->id}/performance?period=q2")
            ->assertOk()
            ->assertJsonPath('data.workplan_id', $plan->id)
            ->assertJsonPath('data.revision_no', 1)
            ->assertJsonPath('data.period', 'q2')
            ->assertJsonPath('data.mda.official_score', 0.8)
            ->assertJsonPath('data.reporting_completeness.expected_reports', 1)
            ->assertJsonPath('data.reporting_completeness.verified_reports', 1)
            ->assertJsonPath('data.reporting_completeness.missing_or_unverified_reports', 0)
            ->assertJsonPath('data.departments.0.reporting_completeness.verified_reports', 1)
            ->assertJsonPath('data.departments.0.objectives.0.financial_execution.reported_expenditure', 25)
            ->assertJsonPath('data.departments.0.objectives.0.activities.0.indicators.0.actual_value', 40)
            ->assertJsonPath('data.departments.0.objectives.0.activities.0.indicators.0.official_score', 0.8);
    }

    public function test_performance_endpoint_requires_permission_and_hides_other_mda_workplans(): void
    {
        [$mda] = $this->user('MDA Admin');
        $plan = $this->plan($mda);
        $viewer = User::factory()->mdaUser($mda)->create();
        UserAccessScope::query()->create(['user_id'=>$viewer->id, 'scope_type'=>'mda', 'mda_id'=>$mda->id]);
        $this->actingAs($viewer)->getJson("/api/workplans/{$plan->id}/performance?period=q1")->assertForbidden();

        [, $other] = $this->user('MDA Admin');
        $this->actingAs($other)->getJson("/api/workplans/{$plan->id}/performance?period=q1")->assertNotFound();
    }

    /** @return array{0: Mda, 1: User} */
    private function user(string $role): array
    {
        $mda = Mda::factory()->create();
        $user = User::factory()->mdaUser($mda)->create();
        $user->assignRole($role);
        UserAccessScope::query()->create(['user_id'=>$user->id, 'scope_type'=>'mda', 'mda_id'=>$mda->id]);
        return [$mda, $user];
    }

    private function plan(Mda $mda): Workplan
    {
        $department = Department::factory()->create(['mda_id'=>$mda->id]);
        $plan = Workplan::query()->create(['mda_id'=>$mda->id, 'year'=>2027, 'revision_no'=>1, 'title'=>'Performance plan', 'status'=>'active']);
        $objective = $plan->objectives()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'code'=>'OBJ', 'title'=>'Objective', 'performance_weight'=>1]);
        $activity = $objective->activities()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'activity_code'=>'ACT', 'title'=>'Activity', 'start_date'=>'2027-01-01', 'end_date'=>'2027-12-31', 'planned_cost'=>100, 'funding_source'=>'Budget', 'performance_weight'=>1]);
        $indicator = $activity->indicators()->create(['mda_id'=>$mda->id, 'code'=>'KPI', 'indicator'=>'Delivery', 'annual_target_value'=>100, 'target_mode'=>'absolute', 'direction'=>'increase', 'weight'=>1, 'is_required'=>true]);
        foreach (['q1'=>25, 'q2'=>50, 'q3'=>75, 'q4'=>100, 'annual'=>100] as $period=>$target) $indicator->targets()->create(['mda_id'=>$mda->id, 'period'=>$period, 'target_value'=>$target]);
        return $plan;
    }
}
