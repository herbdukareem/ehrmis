<?php

namespace App\Http\Controllers\Api;

use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Services\WorkplanPerformanceCalculationService;
use App\Enums\WorkplanTargetPeriod;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkplanPerformanceResource;
use Illuminate\Http\Request;

class WorkplanPerformanceController extends Controller
{
    public function show(Request $request, Workplan $workplan, WorkplanPerformanceCalculationService $service): WorkplanPerformanceResource
    {
        abort_unless($request->user()->can('view-workplan-performance'), 403);
        $this->authorize('view', $workplan);

        $period = $request->validate(['period' => ['required', 'in:'.implode(',', array_map(fn (WorkplanTargetPeriod $case) => $case->value, WorkplanTargetPeriod::cases()))]])['period'];

        return new WorkplanPerformanceResource($service->calculate($workplan, $period));
    }
}
