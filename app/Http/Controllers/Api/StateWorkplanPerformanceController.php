<?php

namespace App\Http\Controllers\Api;

use App\Domain\Workplan\Services\StateWorkplanPerformanceService;
use App\Enums\WorkplanTargetPeriod;
use App\Http\Controllers\Controller;
use App\Http\Resources\StateWorkplanPerformanceResource;
use Illuminate\Http\Request;

class StateWorkplanPerformanceController extends Controller
{
    public function show(Request $request, StateWorkplanPerformanceService $service): StateWorkplanPerformanceResource
    {
        abort_unless($request->user()->can('view-workplan-performance') && $request->user()->hasGlobalMdaAccess(), 403);
        $values = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period' => ['required', 'in:'.implode(',', array_map(fn (WorkplanTargetPeriod $case) => $case->value, WorkplanTargetPeriod::cases()))],
            'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
        ]);

        return new StateWorkplanPerformanceResource($service->calculate($values['year'], $values['period'], $values['mda_id'] ?? null));
    }
}
