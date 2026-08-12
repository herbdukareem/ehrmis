<?php
namespace App\Http\Controllers\Api;
use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanObjective;
use App\Domain\Workplan\Services\WorkplanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workplan\StoreWorkplanObjectiveRequest;
use App\Http\Requests\Workplan\UpdateWorkplanObjectiveRequest;
use Illuminate\Http\JsonResponse;
class WorkplanObjectiveController extends Controller {
 public function store(StoreWorkplanObjectiveRequest $request, Workplan $workplan, WorkplanService $service): JsonResponse { $this->authorize('update',$workplan); return response()->json(['data'=>$service->createObjective($workplan,$request->validated())],201); }
 public function update(UpdateWorkplanObjectiveRequest $request, WorkplanObjective $objective, WorkplanService $service): JsonResponse { $this->authorize('update',$objective); return response()->json(['data'=>$service->updateObjective($objective,$request->validated())]); }
 public function destroy(WorkplanObjective $objective, WorkplanService $service): JsonResponse { $this->authorize('delete',$objective); $service->deleteObjective($objective); return response()->json(status:204); }
}
