<?php

namespace Tests\Feature;

use App\Domain\Workplan\Services\WorkplanPerformanceRankingService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StateWorkplanPerformanceRankingViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(RolesAndPermissionsSeeder::class); }

    public function test_global_user_receives_server_provided_ranking_and_threshold(): void
    {
        $service = Mockery::mock(WorkplanPerformanceRankingService::class);
        $service->shouldReceive('rankings')->once()->with(2027, 'q2')->andReturn(['year'=>2027,'period'=>'q2','minimum_reporting_completeness'=>0.80,'state'=>[],'mdas'=>[['id'=>1,'name'=>'Alpha','workplan_id'=>11,'revision_no'=>2,'rank'=>1,'rank_status'=>'eligible','rank_eligible'=>true,'ranking_score'=>0.90,'official_score'=>0.90,'raw_achievement_ratio'=>1.20,'eligible_weight'=>3,'reporting_completeness'=>['ratio'=>0.80,'verified_reports'=>4,'missing_or_unverified_reports'=>1],'evidence_coverage'=>['ratio'=>0.5],'financial_execution'=>['ratio'=>0.4]]]]);
        app()->instance(WorkplanPerformanceRankingService::class, $service);
        $user = User::factory()->superAdmin()->create(); $user->assignRole('Super Admin');

        $this->actingAs($user)->getJson('/api/workplan-performance/state/rankings?year=2027&period=q2')->assertOk()
            ->assertJsonPath('data.minimum_reporting_completeness', 0.8)
            ->assertJsonPath('data.mdas.0.rank', 1)
            ->assertJsonPath('data.mdas.0.raw_achievement_ratio', 1.2)
            ->assertJsonPath('data.mdas.0.workplan_id', 11);
    }

    public function test_state_ranking_and_trends_are_global_only_and_validate_input(): void
    {
        $user = User::factory()->mdaUser()->create(); $user->assignRole('MDA Admin');
        $this->actingAs($user)->getJson('/api/workplan-performance/state/rankings?year=2027&period=q2')->assertForbidden();
        $global = User::factory()->superAdmin()->create(); $global->assignRole('Super Admin');
        $this->actingAs($global)->getJson('/api/workplan-performance/state/rankings?year=2027&period=bad')->assertUnprocessable();
        $this->actingAs($global)->getJson('/api/workplan-performance/state/trends')->assertUnprocessable();
    }
}
