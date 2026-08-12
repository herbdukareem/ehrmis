<?php

namespace App\Http\Controllers\Api;

use App\Domain\Workplan\Services\WorkplanPerformanceRankingService;
use App\Enums\WorkplanTargetPeriod;
use App\Http\Controllers\Controller;
use App\Http\Resources\StateWorkplanPerformanceResource;
use Illuminate\Http\Request;

class StateWorkplanPerformanceRankingController extends Controller
{
    public function rankings(Request $request, WorkplanPerformanceRankingService $service): StateWorkplanPerformanceResource
    {
        $this->authorizeState($request);
        $values = $this->periodValues($request);
        return new StateWorkplanPerformanceResource($service->rankings($values['year'], $values['period']));
    }

    public function trends(Request $request, WorkplanPerformanceRankingService $service): StateWorkplanPerformanceResource
    {
        $this->authorizeState($request);
        $year = $request->validate(['year'=>['required','integer','min:2000','max:2100']])['year'];
        return new StateWorkplanPerformanceResource($service->stateTrends($year));
    }

    private function authorizeState(Request $request): void { abort_unless($request->user()->can('view-workplan-performance') && $request->user()->hasGlobalMdaAccess(), 403); }
    private function periodValues(Request $request): array { return $request->validate(['year'=>['required','integer','min:2000','max:2100'], 'period'=>['required','in:'.implode(',', array_map(fn (WorkplanTargetPeriod $case) => $case->value, WorkplanTargetPeriod::cases()))]]); }
}
