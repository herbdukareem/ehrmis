<?php

namespace Tests\Unit;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanActivity;
use App\Domain\Workplan\Models\WorkplanIndicatorProgress;
use App\Domain\Workplan\Models\WorkplanProgressReport;
use App\Domain\Workplan\Services\StateWorkplanPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateWorkplanPerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_score_uses_eligible_weight_and_operational_metrics_use_raw_counts(): void
    {
        [$mdaOne, $one] = $this->workplan('Alpha', 1, 80, true, 20);
        [$mdaTwo, $two] = $this->workplan('Bravo', 3, 60, true, 30);
        [$mdaThree] = $this->workplan('Charlie', 2, 0, false);

        $result = app(StateWorkplanPerformanceService::class)->calculate(2027, 'q1');

        $this->assertSame(3, $result['mda_count_expected_to_report']);
        $this->assertSame(2, $result['mda_count_with_verified_reporting']);
        $this->assertEqualsWithDelta(2.6 / 6, $result['state']['official_score'], 0.00001);
        $this->assertEqualsWithDelta(2 / 3, $result['state']['reporting_completeness']['ratio'], 0.00001);
        $this->assertEqualsWithDelta(0.50, $result['state']['evidence_coverage']['ratio'], 0.00001);
        $this->assertEqualsWithDelta(50 / 600, $result['state']['financial_execution']['ratio'], 0.00001);
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($result['mdas'], 'name'));
        $this->assertSame(1, $result['mdas'][0]['revision_no']);
        $this->assertSame($mdaOne->id, $result['mdas'][0]['id']);
        $this->assertSame($mdaTwo->id, $result['mdas'][1]['id']);
    }

    public function test_period_owned_reports_choose_historical_revision_before_active_fallback(): void
    {
        $mda = Mda::factory()->create(['name'=>'Historical']);
        [, $revisionOne, $oldActivity] = $this->workplanFor($mda, 1, 20, 'superseded');
        [, $revisionTwo] = $this->workplanFor($mda, 4, 80, 'active');
        $this->report($oldActivity, 20, true);

        $result = app(StateWorkplanPerformanceService::class)->calculate(2027, 'q1', $mda->id);

        $this->assertCount(1, $result['mdas']);
        $this->assertSame($revisionOne->id, $result['mdas'][0]['workplan_id']);
        $this->assertSame(1, $result['mdas'][0]['revision_no']);
        $this->assertEqualsWithDelta(0.2, $result['state']['official_score'], 0.00001);
        $this->assertNotSame($revisionTwo->id, $result['mdas'][0]['workplan_id']);
    }

    public function test_zero_eligible_planning_weight_remains_visible_without_expected_reporting(): void
    {
        $mda = Mda::factory()->create(['name'=>'No Data']);
        $this->workplanFor($mda, 0, 0, 'active');

        $result = app(StateWorkplanPerformanceService::class)->calculate(2027, 'q1', $mda->id);

        $this->assertCount(1, $result['mdas']);
        $this->assertSame(0, $result['mda_count_expected_to_report']);
        $this->assertNull($result['mdas'][0]['official_score']);
        $this->assertNull($result['mdas'][0]['reporting_completeness']['ratio']);
        $this->assertSame(0, $result['mdas'][0]['reporting_completeness']['expected_reports']);
    }

    /** @return array{0: Mda, 1: Workplan} */
    private function workplan(string $name, float $weight, float $actual, bool $verified, float $expenditure = 0): array
    {
        $mda = Mda::factory()->create(['name'=>$name]);
        [, $plan, $activity] = $this->workplanFor($mda, $weight, $actual, 'active');
        if ($verified) $this->report($activity, $actual, $name === 'Alpha', $expenditure);
        return [$mda, $plan];
    }

    /** @return array{0: Mda, 1: Workplan, 2: WorkplanActivity} */
    private function workplanFor(Mda $mda, float $weight, float $actual, string $status): array
    {
        $department = Department::factory()->create(['mda_id'=>$mda->id]);
        $revision = Workplan::withoutGlobalScopes()->where('mda_id', $mda->id)->where('year', 2027)->max('revision_no') + 1;
        $plan = Workplan::query()->create(['mda_id'=>$mda->id, 'year'=>2027, 'revision_no'=>$revision, 'title'=>'Plan', 'status'=>$status]);
        $objective = $plan->objectives()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'code'=>'OBJ-'.$revision, 'title'=>'Objective', 'performance_weight'=>1]);
        $activity = $objective->activities()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'activity_code'=>'ACT-'.$revision, 'title'=>'Activity', 'start_date'=>'2027-01-01', 'end_date'=>'2027-12-31', 'planned_cost'=>100 * $weight, 'funding_source'=>'Budget', 'performance_weight'=>$weight]);
        $indicator = $activity->indicators()->create(['mda_id'=>$mda->id, 'code'=>'KPI-'.$revision, 'indicator'=>'Delivery', 'annual_target_value'=>100, 'target_mode'=>'absolute', 'direction'=>'increase', 'weight'=>1, 'is_required'=>true]);
        foreach (['q1'=>100, 'q2'=>100, 'q3'=>100, 'q4'=>100, 'annual'=>100] as $period=>$target) $indicator->targets()->create(['mda_id'=>$mda->id, 'period'=>$period, 'target_value'=>$target]);
        return [$mda, $plan, $activity];
    }

    private function report(WorkplanActivity $activity, float $actual, bool $evidence, float $expenditure = 0): void
    {
        $plan = $activity->objective->workplan;
        $report = WorkplanProgressReport::query()->create(['mda_id'=>$activity->mda_id, 'workplan_id'=>$plan->id, 'workplan_activity_id'=>$activity->id, 'period'=>'q1', 'status'=>'verified', 'reported_expenditure'=>$expenditure]);
        WorkplanIndicatorProgress::query()->create(['mda_id'=>$activity->mda_id, 'workplan_progress_report_id'=>$report->id, 'workplan_indicator_id'=>$activity->indicators()->firstOrFail()->id, 'target_value_snapshot'=>100, 'actual_value'=>$actual]);
        if ($evidence) $report->evidence()->create(['mda_id'=>$activity->mda_id, 'workplan_id'=>$plan->id, 'workplan_activity_id'=>$activity->id, 'title'=>'Evidence', 'evidence_type'=>'link', 'external_url'=>'https://example.test/evidence']);
    }
}
