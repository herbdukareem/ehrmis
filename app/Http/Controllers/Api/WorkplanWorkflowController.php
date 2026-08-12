<?php

namespace App\Http\Controllers\Api;

use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Services\WorkplanQueryService;
use App\Domain\Workplan\Services\WorkplanWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workplan\CreateWorkplanAmendmentRequest;
use App\Http\Resources\WorkplanDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkplanWorkflowController extends Controller
{
    public function submit(Request $request, Workplan $workplan, WorkplanWorkflowService $service): JsonResponse { return response()->json(['data' => $service->submit($workplan, $request->user())]); }
    public function approve(Request $request, Workplan $workplan, WorkplanWorkflowService $service): JsonResponse { return response()->json(['data' => $service->approve($workplan, $request->user())]); }
    public function return(Request $request, Workplan $workplan, WorkplanWorkflowService $service): JsonResponse { $data = $request->validate(['comment' => ['required', 'string', 'max:1000']]); return response()->json(['data' => $service->return($workplan, $request->user(), $data['comment'])]); }
    public function reject(Request $request, Workplan $workplan, WorkplanWorkflowService $service): JsonResponse { $data = $request->validate(['comment' => ['required', 'string', 'max:1000']]); return response()->json(['data' => $service->reject($workplan, $request->user(), $data['comment'])]); }
    public function activate(Request $request, Workplan $workplan, WorkplanWorkflowService $service): JsonResponse { return response()->json(['data' => $service->activate($workplan, $request->user())]); }
    public function close(Request $request, Workplan $workplan, WorkplanWorkflowService $service): JsonResponse { return response()->json(['data' => $service->close($workplan, $request->user())]); }

    public function amendment(CreateWorkplanAmendmentRequest $request, Workplan $workplan, WorkplanWorkflowService $service, WorkplanQueryService $queries): WorkplanDetailResource
    {
        $amendment = $service->amendment($workplan, $request->user(), $request->validated('amendment_reason'));

        return new WorkplanDetailResource($queries->detail($amendment));
    }
}
