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

class StateWorkplanPerformanceViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(RolesAndPermissionsSeeder::class); }

    public function test_global_user_receives_alphabetical_state_rows_with_exact_governing_revision(): void
    {
        $alpha = $this->plan('Alpha', 'superseded', 1); $bravo = $this->plan('Bravo', 'active', 2);
        $this->verified($alpha[2], 80); $this->verified($bravo[2], 60);
        $user = User::factory()->superAdmin()->create(); $user->assignRole('Super Admin');

        $this->actingAs($user)->getJson('/api/workplan-performance/state?year=2027&period=q1')
            ->assertOk()
            ->assertJsonPath('data.year', 2027)
            ->assertJsonPath('data.period', 'q1')
            ->assertJsonPath('data.mda_count_expected_to_report', 2)
            ->assertJsonPath('data.mdas.0.name', 'Alpha')
            ->assertJsonPath('data.mdas.0.workplan_id', $alpha[1]->id)
            ->assertJsonPath('data.mdas.0.revision_no', 1)
            ->assertJsonPath('data.mdas.1.name', 'Bravo')
            ->assertJsonPath('data.mdas.1.workplan_id', $bravo[1]->id)
            ->assertJsonPath('data.state.reporting_completeness.verified_reports', 2);
    }

    public function test_mda_scoped_user_is_denied_and_validation_and_empty_state_are_safe(): void
    {
        $mda = Mda::factory()->create();
        $mdaUser = User::factory()->mdaUser($mda)->create(); $mdaUser->assignRole('MDA Admin');
        UserAccessScope::query()->create(['user_id'=>$mdaUser->id, 'scope_type'=>'mda', 'mda_id'=>$mda->id]);
        $this->actingAs($mdaUser)->getJson('/api/workplan-performance/state?year=2027&period=q1')->assertForbidden();

        $global = User::factory()->superAdmin()->create(); $global->assignRole('Super Admin');
        $this->actingAs($global)->getJson('/api/workplan-performance/state?year=2027&period=invalid')->assertUnprocessable();
        $this->actingAs($global)->getJson('/api/workplan-performance/state?year=2099&period=q1')->assertOk()
            ->assertJsonPath('data.mda_count_expected_to_report', 0)
            ->assertJsonPath('data.state.official_score', null)
            ->assertJsonCount(0, 'data.mdas');
    }

    /** @return array{0: Mda, 1: Workplan, 2: \App\Domain\Workplan\Models\WorkplanActivity} */
    private function plan(string $name, string $status, int $revision): array
    {
        $mda = Mda::factory()->create(['name'=>$name]); $department = Department::factory()->create(['mda_id'=>$mda->id]);
        $plan = Workplan::query()->create(['mda_id'=>$mda->id, 'year'=>2027, 'revision_no'=>$revision, 'title'=>'Plan', 'status'=>$status]);
        $objective = $plan->objectives()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'code'=>'OBJ', 'title'=>'Objective', 'performance_weight'=>1]);
        $activity = $objective->activities()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'activity_code'=>'ACT', 'title'=>'Activity', 'start_date'=>'2027-01-01', 'end_date'=>'2027-12-31', 'planned_cost'=>100, 'funding_source'=>'Budget', 'performance_weight'=>1]);
        $indicator = $activity->indicators()->create(['mda_id'=>$mda->id, 'code'=>'KPI', 'indicator'=>'Delivery', 'annual_target_value'=>100, 'target_mode'=>'absolute', 'direction'=>'increase', 'weight'=>1, 'is_required'=>true]);
        foreach (['q1'=>100, 'q2'=>100, 'q3'=>100, 'q4'=>100, 'annual'=>100] as $period=>$target) $indicator->targets()->create(['mda_id'=>$mda->id, 'period'=>$period, 'target_value'=>$target]);
        return [$mda, $plan, $activity];
    }

    private function verified($activity, float $actual): void
    {
        $plan = $activity->objective->workplan;
        $report = WorkplanProgressReport::query()->create(['mda_id'=>$activity->mda_id, 'workplan_id'=>$plan->id, 'workplan_activity_id'=>$activity->id, 'period'=>'q1', 'status'=>'verified']);
        WorkplanIndicatorProgress::query()->create(['mda_id'=>$activity->mda_id, 'workplan_progress_report_id'=>$report->id, 'workplan_indicator_id'=>$activity->indicators()->firstOrFail()->id, 'target_value_snapshot'=>100, 'actual_value'=>$actual]);
    }
}
