<?php

namespace Tests\Unit;

use App\Domain\Workplan\Services\StateWorkplanPerformanceService;
use App\Domain\Workplan\Services\WorkplanPerformanceRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkplanPerformanceRankingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rankings_use_eligibility_shared_ranks_and_keep_ineligible_rows_visible(): void
    {
        $state = Mockery::mock(StateWorkplanPerformanceService::class);
        $state->shouldReceive('calculate')->once()->with(2027, 'q2')->andReturn($this->state([
            $this->row('Bravo', .90, .80, 2),
            $this->row('Alpha', .90, 1.00, 5),
            $this->row('Delta', .80, .80, 3),
            $this->row('Charlie', .99, .79, 4),
            $this->row('Echo', null, null, 0),
        ]));
        app()->instance(StateWorkplanPerformanceService::class, $state);

        $result = app(WorkplanPerformanceRankingService::class)->rankings(2027, 'q2');

        $this->assertSame(0.80, $result['minimum_reporting_completeness']);
        $this->assertSame(['Alpha', 'Bravo', 'Delta', 'Charlie', 'Echo'], array_column($result['mdas'], 'name'));
        $this->assertSame([1, 1, 3, null, null], array_column($result['mdas'], 'rank'));
        $this->assertSame(['eligible', 'eligible', 'eligible', 'insufficient_reporting', 'no_eligible_plan_data'], array_column($result['mdas'], 'rank_status'));
        $this->assertSame(0.99, $result['mdas'][3]['ranking_score']);
        $this->assertFalse($result['mdas'][3]['rank_eligible']);
        $this->assertNull($result['mdas'][4]['ranking_score']);
    }

    public function test_trends_resolve_every_period_through_the_state_service_without_derived_trend_score(): void
    {
        $state = Mockery::mock(StateWorkplanPerformanceService::class);
        $state->shouldReceive('calculate')->times(5)->andReturnUsing(function (int $year, $period, int $mdaId) {
            $value = $period->value;
            $revisions = ['q1'=>1, 'q2'=>2, 'q3'=>2, 'q4'=>3, 'annual'=>3];
            return $this->state([$this->row('Alpha', .80, .90, 1, $revisions[$value])]);
        });
        app()->instance(StateWorkplanPerformanceService::class, $state);

        $result = app(WorkplanPerformanceRankingService::class)->trends(2027, 10);

        $this->assertSame(['q1', 'q2', 'q3', 'q4', 'annual'], array_column($result['observations'], 'period'));
        $this->assertSame([1, 2, 2, 3, 3], array_column($result['observations'], 'revision_no'));
        $this->assertArrayNotHasKey('trend_score', $result['observations'][0]);
        $this->assertSame('eligible', $result['observations'][0]['rank_status']);
    }

    private function state(array $rows): array { return ['state'=>[], 'mdas'=>$rows]; }
    private function row(string $name, ?float $score, ?float $completeness, float $weight, int $revision = 1): array
    {
        return ['id'=>crc32($name), 'name'=>$name, 'code'=>$name, 'workplan_id'=>crc32($name.'plan'), 'revision_no'=>$revision, 'official_score'=>$score, 'raw_achievement_ratio'=>$score, 'eligible_weight'=>$weight, 'reporting_completeness'=>['expected_reports'=>$weight > 0 ? 1 : 0, 'verified_reports'=>$completeness === null ? 0 : 1, 'missing_or_unverified_reports'=>0, 'ratio'=>$completeness], 'evidence_coverage'=>['verified_reports'=>0, 'verified_reports_with_evidence'=>0, 'ratio'=>null], 'financial_execution'=>['planned_cost'=>0.0, 'reported_expenditure'=>0.0, 'ratio'=>null]];
    }
}
