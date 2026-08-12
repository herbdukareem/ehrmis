<?php

namespace Tests\Unit;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanActivity;
use App\Domain\Workplan\Models\WorkplanEvidence;
use App\Domain\Workplan\Models\WorkplanIndicator;
use App\Domain\Workplan\Models\WorkplanIndicatorProgress;
use App\Domain\Workplan\Models\WorkplanProgressReport;
use App\Domain\Workplan\Services\WorkplanPerformanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkplanPerformanceCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_verified_reports_contribute_and_missing_expected_reports_score_zero(): void
    {
        [$plan, $activities] = $this->planWithActivities([40, 30, 30]);
        $this->verified($activities[0], [['actual' => 100]], 50, true);
        $this->verified($activities[1], [['actual' => 80]], 60);
        $this->report($activities[2], 'submitted', [['actual' => 100]]);

        $result = app(WorkplanPerformanceCalculationService::class)->calculate($plan, 'q1');

        $this->assertEqualsWithDelta(0.64, $result['mda']['official_score'], 0.00001);
        $this->assertEqualsWithDelta(0.64, $result['mda']['raw_achievement_ratio'], 0.00001);
        $this->assertSame(3, $result['reporting_completeness']['expected_reports']);
        $this->assertSame(2, $result['reporting_completeness']['verified_reports']);
        $this->assertEqualsWithDelta(2 / 3, $result['reporting_completeness']['ratio'], 0.00001);
        $this->assertEqualsWithDelta(0.5, $result['evidence_coverage']['ratio'], 0.00001);
        $this->assertEqualsWithDelta(110 / 300, $result['financial_execution']['ratio'], 0.00001);
        $this->assertSame('submitted', $result['objectives'][0]['activities'][2]['report_status']);
        $this->assertFalse($result['objectives'][0]['activities'][2]['verified']);
    }

    public function test_scores_cap_overachievement_and_handle_decrease_and_milestones(): void
    {
        [$plan, $activities] = $this->planWithActivities([1], [[
            ['weight' => 40, 'mode' => 'absolute', 'direction' => 'increase'],
            ['weight' => 30, 'mode' => 'absolute', 'direction' => 'decrease'],
            ['weight' => 30, 'mode' => 'milestone', 'direction' => 'milestone'],
        ]]);
        $this->verified($activities[0], [['actual' => 130], ['actual' => 50], ['actual' => 0]]);

        $result = app(WorkplanPerformanceCalculationService::class)->calculate($plan, 'q1');
        $activity = $result['objectives'][0]['activities'][0];

        $this->assertEqualsWithDelta(0.70, $activity['official_score'], 0.00001);
        $this->assertEqualsWithDelta(1.12, $activity['raw_achievement_ratio'], 0.00001);
        $this->assertEqualsWithDelta(1.30, $activity['indicators'][0]['raw_achievement_ratio'], 0.00001);
        $this->assertEqualsWithDelta(1.0, $activity['indicators'][0]['official_score'], 0.00001);
        $this->assertEqualsWithDelta(2.0, $activity['indicators'][1]['raw_achievement_ratio'], 0.00001);
        $this->assertSame(0.0, $activity['indicators'][2]['official_score']);
    }

    public function test_calculation_isolation_preserves_the_governing_workplan_revision(): void
    {
        [$revisionOne, $oldActivities] = $this->planWithActivities([1], null, 'superseded', 2027, 1);
        [$revisionTwo, $newActivities] = $this->planWithActivities([1], null, 'active', 2027, 2, $revisionOne->mda_id);
        $this->verified($oldActivities[0], [['actual' => 20]]);
        $this->verified($newActivities[0], [['actual' => 80]]);

        $service = app(WorkplanPerformanceCalculationService::class);
        $old = $service->calculate($revisionOne, 'q1');
        $new = $service->calculate($revisionTwo, 'q1');

        $this->assertSame(1, $old['revision_no']);
        $this->assertEqualsWithDelta(0.20, $old['mda']['official_score'], 0.00001);
        $this->assertSame(2, $new['revision_no']);
        $this->assertEqualsWithDelta(0.80, $new['mda']['official_score'], 0.00001);
    }

    public function test_annual_is_independent_and_zero_denominators_and_evidence_coverage_are_null(): void
    {
        [$plan] = $this->planWithActivities([0]);

        $result = app(WorkplanPerformanceCalculationService::class)->calculate($plan, 'annual');

        $this->assertSame('annual', $result['period']);
        $this->assertSame(0, $result['reporting_completeness']['expected_reports']);
        $this->assertSame(0, $result['reporting_completeness']['verified_reports']);
        $this->assertNull($result['reporting_completeness']['ratio']);
        $this->assertNull($result['evidence_coverage']['ratio']);
        $this->assertNull($result['mda']['official_score']);
        $this->assertNull($result['mda']['raw_achievement_ratio']);
    }

    /** @return array{0: Workplan, 1: array<int, WorkplanActivity>} */
    private function planWithActivities(array $activityWeights, ?array $indicatorDefinitions = null, string $status = 'active', int $year = 2027, int $revision = 1, ?int $mdaId = null): array
    {
        $mda = $mdaId ? Mda::query()->findOrFail($mdaId) : Mda::factory()->create();
        $department = Department::factory()->create(['mda_id' => $mda->id]);
        $plan = Workplan::query()->create(['mda_id'=>$mda->id, 'year'=>$year, 'revision_no'=>$revision, 'title'=>"Revision {$revision}", 'status'=>$status]);
        $objective = $plan->objectives()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'code'=>'OBJ-'.$revision, 'title'=>'Objective', 'performance_weight'=>1]);
        $activities = [];
        foreach ($activityWeights as $index => $weight) {
            $activity = $objective->activities()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'activity_code'=>'ACT-'.($index + 1), 'title'=>'Activity '.($index + 1), 'start_date'=>"{$year}-01-01", 'end_date'=>"{$year}-12-31", 'planned_cost'=>100, 'funding_source'=>'Budget', 'performance_weight'=>$weight]);
            foreach (($indicatorDefinitions[$index] ?? [['weight'=>1, 'mode'=>'absolute', 'direction'=>'increase']]) as $indicatorIndex => $definition) {
                $indicator = $activity->indicators()->create(['mda_id'=>$mda->id, 'code'=>"KPI-{$index}-{$indicatorIndex}", 'indicator'=>'Indicator', 'annual_target_value'=>100, 'target_mode'=>$definition['mode'], 'direction'=>$definition['direction'], 'weight'=>$definition['weight'], 'is_required'=>true]);
                foreach (['q1'=>100, 'q2'=>100, 'q3'=>100, 'q4'=>100, 'annual'=>100] as $period=>$target) $indicator->targets()->create(['mda_id'=>$mda->id, 'period'=>$period, 'target_value'=>$target]);
            }
            $activities[] = $activity->fresh('indicators');
        }
        return [$plan, $activities];
    }

    private function verified(WorkplanActivity $activity, array $actuals, float $expenditure = 0, bool $evidence = false): WorkplanProgressReport
    {
        return $this->report($activity, 'verified', $actuals, $expenditure, $evidence);
    }

    private function report(WorkplanActivity $activity, string $status, array $actuals, float $expenditure = 0, bool $evidence = false): WorkplanProgressReport
    {
        $plan = $activity->objective->workplan;
        $report = WorkplanProgressReport::query()->create(['mda_id'=>$activity->mda_id, 'workplan_id'=>$plan->id, 'workplan_activity_id'=>$activity->id, 'period'=>'q1', 'status'=>$status, 'reported_expenditure'=>$expenditure]);
        foreach ($activity->indicators as $index => $indicator) {
            WorkplanIndicatorProgress::query()->create(['mda_id'=>$activity->mda_id, 'workplan_progress_report_id'=>$report->id, 'workplan_indicator_id'=>$indicator->id, 'target_value_snapshot'=>100, 'actual_value'=>$actuals[$index]['actual'] ?? null]);
        }
        if ($evidence) WorkplanEvidence::query()->create(['mda_id'=>$activity->mda_id, 'workplan_id'=>$plan->id, 'workplan_activity_id'=>$activity->id, 'workplan_progress_report_id'=>$report->id, 'title'=>'Evidence', 'evidence_type'=>'link', 'external_url'=>'https://example.test/evidence']);
        return $report;
    }
}
