<?php

namespace Tests\Feature;

use App\Domain\Workplan\Services\WorkplanPerformanceRankingService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StateExecutivePerformanceViewTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed(RolesAndPermissionsSeeder::class); }

    public function test_global_user_receives_server_derived_executive_summary_ranking_and_trends(): void
    {
        $service = Mockery::mock(WorkplanPerformanceRankingService::class);
        $rankings = ['year'=>2027,'period'=>'q2','minimum_reporting_completeness'=>config('workplan_performance.ranking_completeness_threshold'),'state'=>['official_score'=>.7,'reporting_completeness'=>['expected_reports'=>10,'verified_reports'=>8,'missing_or_unverified_reports'=>2,'ratio'=>.8],'evidence_coverage'=>['ratio'=>.5],'financial_execution'=>['planned_cost'=>100,'reported_expenditure'=>50,'ratio'=>.5],'eligible_weight'=>3],'mdas'=>[['id'=>1,'name'=>'Alpha','workplan_id'=>55,'revision_no'=>2,'rank'=>1,'rank_status'=>'eligible','rank_eligible'=>true,'official_score'=>.9,'raw_achievement_ratio'=>1.2,'eligible_weight'=>3,'reporting_completeness'=>['expected_reports'=>2,'verified_reports'=>2,'missing_or_unverified_reports'=>0,'ratio'=>1],'evidence_coverage'=>['ratio'=>.5],'financial_execution'=>['planned_cost'=>100,'reported_expenditure'=>50,'ratio'=>.5]],['id'=>2,'name'=>'Bravo','workplan_id'=>66,'revision_no'=>1,'rank'=>null,'rank_status'=>'insufficient_reporting','rank_eligible'=>false,'official_score'=>.8,'raw_achievement_ratio'=>.8,'eligible_weight'=>2,'reporting_completeness'=>['expected_reports'=>2,'verified_reports'=>1,'missing_or_unverified_reports'=>1,'ratio'=>.5],'evidence_coverage'=>['ratio'=>null],'financial_execution'=>['planned_cost'=>10,'reported_expenditure'=>0,'ratio'=>0]],['id'=>3,'name'=>'No Plan','workplan_id'=>77,'revision_no'=>1,'rank'=>null,'rank_status'=>'no_eligible_plan_data','rank_eligible'=>false,'official_score'=>null,'raw_achievement_ratio'=>null,'eligible_weight'=>0,'reporting_completeness'=>['expected_reports'=>0,'verified_reports'=>0,'missing_or_unverified_reports'=>0,'ratio'=>null],'evidence_coverage'=>['ratio'=>null],'financial_execution'=>['planned_cost'=>0,'reported_expenditure'=>0,'ratio'=>null]]]];
        $service->shouldReceive('rankings')->once()->with(2027,'q2')->andReturn($rankings);
        $service->shouldReceive('stateTrends')->once()->with(2027)->andReturn(['year'=>2027,'periods'=>['q1','q2','q3','q4','annual'],'mdas'=>[['id'=>1,'name'=>'Alpha','observations'=>['q2'=>['workplan_id'=>55,'revision_no'=>2,'official_score'=>.9]]]]]);
        app()->instance(WorkplanPerformanceRankingService::class,$service);
        $user=User::factory()->superAdmin()->create(); $user->assignRole('Super Admin');
        $this->actingAs($user)->getJson('/api/workplan-performance/state/executive?year=2027&period=q2')->assertOk()
            ->assertJsonPath('data.ranking_completeness_threshold', config('workplan_performance.ranking_completeness_threshold'))
            ->assertJsonPath('data.classifications.ranked_count',1)->assertJsonPath('data.classifications.insufficient_reporting_count',1)->assertJsonPath('data.classifications.no_eligible_plan_data_count',1)
            ->assertJsonPath('data.mdas.0.rank_status','ranked')->assertJsonPath('data.mdas.0.workplan_id',55)->assertJsonPath('data.trends.mdas.0.observations.q2.revision_no',2);
    }

    public function test_executive_endpoint_is_global_only_and_validates_year_and_period(): void
    {
        $mdaUser=User::factory()->mdaUser()->create(); $mdaUser->assignRole('MDA Admin');
        $this->actingAs($mdaUser)->getJson('/api/workplan-performance/state/executive?year=2027&period=q2')->assertForbidden();
        $global=User::factory()->superAdmin()->create(); $global->assignRole('Super Admin');
        $this->actingAs($global)->getJson('/api/workplan-performance/state/executive?year=bad&period=q2')->assertUnprocessable();
        $this->actingAs($global)->getJson('/api/workplan-performance/state/executive?year=2027&period=bad')->assertUnprocessable();
    }
}
