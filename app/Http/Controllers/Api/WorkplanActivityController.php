<?php
namespace App\Http\Controllers\Api;
use App\Domain\Workplan\Models\WorkplanActivity;
use App\Domain\Workplan\Models\WorkplanObjective;
use App\Domain\Workplan\Services\WorkplanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workplan\StoreWorkplanActivityRequest;
use App\Http\Requests\Workplan\SyncWorkplanSupportingStaffRequest;
use App\Http\Requests\Workplan\UpdateWorkplanActivityRequest;
use Illuminate\Http\JsonResponse;
class WorkplanActivityController extends Controller {
 public function store(StoreWorkplanActivityRequest $request, WorkplanObjective $objective, WorkplanService $service): JsonResponse { $this->authorize('update',$objective); return response()->json(['data'=>$service->createActivity($objective,$request->validated())],201); }
 public function update(UpdateWorkplanActivityRequest $request, WorkplanActivity $activity, WorkplanService $service): JsonResponse { $this->authorize('update',$activity); return response()->json(['data'=>$service->updateActivity($activity,$request->validated())]); }
 public function destroy(WorkplanActivity $activity, WorkplanService $service): JsonResponse { $this->authorize('delete',$activity); $service->deleteActivity($activity); return response()->json(status:204); }
 public function syncSupportingStaff(SyncWorkplanSupportingStaffRequest $request, WorkplanActivity $activity, WorkplanService $service): JsonResponse { $this->authorize('update',$activity); return response()->json(['data'=>$service->syncSupportingStaff($activity,$request->validated('staff_ids'))]); }
}
