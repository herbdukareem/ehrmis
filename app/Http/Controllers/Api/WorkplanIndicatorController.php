<?php
namespace App\Http\Controllers\Api;
use App\Domain\Workplan\Models\WorkplanActivity;
use App\Domain\Workplan\Models\WorkplanIndicator;
use App\Domain\Workplan\Services\WorkplanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workplan\StoreWorkplanIndicatorRequest;
use App\Http\Requests\Workplan\SyncWorkplanIndicatorTargetsRequest;
use App\Http\Requests\Workplan\UpdateWorkplanIndicatorRequest;
use Illuminate\Http\JsonResponse;
class WorkplanIndicatorController extends Controller {
 public function store(StoreWorkplanIndicatorRequest $request, WorkplanActivity $activity, WorkplanService $service): JsonResponse { $this->authorize('update',$activity); return response()->json(['data'=>$service->createIndicator($activity,$request->validated())],201); }
 public function update(UpdateWorkplanIndicatorRequest $request, WorkplanIndicator $indicator, WorkplanService $service): JsonResponse { $this->authorize('update',$indicator); return response()->json(['data'=>$service->updateIndicator($indicator,$request->validated())]); }
 public function destroy(WorkplanIndicator $indicator, WorkplanService $service): JsonResponse { $this->authorize('delete',$indicator); $service->deleteIndicator($indicator); return response()->json(status:204); }
 public function syncTargets(SyncWorkplanIndicatorTargetsRequest $request, WorkplanIndicator $indicator, WorkplanService $service): JsonResponse { $this->authorize('update',$indicator); return response()->json(['data'=>$service->syncTargets($indicator,$request->validated('targets'))]); }
}
