<?php

namespace App\Http\Controllers\Api;

use App\Domain\Workplan\Services\WorkplanPerformanceRankingService;
use App\Enums\WorkplanTargetPeriod;
use App\Http\Controllers\Controller;
use App\Http\Resources\StateExecutivePerformanceResource;
use Illuminate\Http\Request;

class StateExecutivePerformanceController extends Controller
{
    public function show(Request $request, WorkplanPerformanceRankingService $rankings): StateExecutivePerformanceResource
    {
        abort_unless($request->user()->can('view-workplan-performance') && $request->user()->hasGlobalMdaAccess(), 403);
        $values = $request->validate(['year'=>['required','integer','min:2000','max:2100'], 'period'=>['required','in:'.implode(',', array_map(fn (WorkplanTargetPeriod $case) => $case->value, WorkplanTargetPeriod::cases()))]]);
        $ranking = $rankings->rankings($values['year'], $values['period']);
        $trends = $rankings->stateTrends($values['year']);
        $rows = array_map(fn ($mda) => [
            ...$mda,
            'rank_status' => $mda['rank_status'] === 'eligible' ? 'ranked' : $mda['rank_status'],
        ], $ranking['mdas']);

        return new StateExecutivePerformanceResource([
            'generated_at'=>now()->toISOString(), 'methodology_version'=>'phase_4_verified_performance_v1', 'year'=>$values['year'], 'period'=>$values['period'], 'ranking_completeness_threshold'=>$ranking['minimum_reporting_completeness'],
            'state'=>$ranking['state'],
            'classifications'=>['ranked_count'=>count(array_filter($rows, fn ($mda) => $mda['rank_status'] === 'ranked')), 'insufficient_reporting_count'=>count(array_filter($rows, fn ($mda) => $mda['rank_status'] === 'insufficient_reporting')), 'no_eligible_plan_data_count'=>count(array_filter($rows, fn ($mda) => $mda['rank_status'] === 'no_eligible_plan_data'))],
            'mdas'=>$rows, 'trends'=>$trends,
        ]);
    }
}
