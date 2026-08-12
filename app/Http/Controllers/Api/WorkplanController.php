<?php

namespace App\Http\Controllers\Api;

use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Services\WorkplanQueryService;
use App\Domain\Workplan\Services\WorkplanService;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Department;
use App\Domain\Staff\Models\Staff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workplan\StoreWorkplanRequest;
use App\Http\Requests\Workplan\UpdateWorkplanRequest;
use App\Http\Resources\WorkplanDetailResource;
use App\Http\Resources\WorkplanListResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkplanController extends Controller
{
    public function index(Request $request, WorkplanQueryService $queries): JsonResponse { $this->authorize('viewAny', Workplan::class); return response()->json(['data' => WorkplanListResource::collection($queries->list($request->user(), $request->validate(['mda_id'=>['nullable','integer'],'year'=>['nullable','integer'],'status'=>['nullable','string']]))->get())->resolve(), 'options' => ['mdas' => Mda::query()->visibleToUser($request->user())->orderBy('name')->get(['id','code','name'])]]); }
    public function store(StoreWorkplanRequest $request, WorkplanService $service): JsonResponse { $this->authorize('create', Workplan::class); $workplan = $service->create($request->validated(), $request->user()); return (new WorkplanDetailResource($workplan->load('mda','preparedBy','objectives')))->response()->setStatusCode(201); }
    public function show(Workplan $workplan, WorkplanQueryService $queries): WorkplanDetailResource { $this->authorize('view', $workplan); return (new WorkplanDetailResource($queries->detail($workplan)))->additional(['options' => ['departments' => Department::query()->where('mda_id', $workplan->mda_id)->orderBy('name')->get(['id','code','name']), 'staff' => Staff::query()->where('mda_id', $workplan->mda_id)->where('status', 'active')->with(['currentEmployment.department','currentEmployment.rank'])->orderBy('full_name')->get()->map(fn (Staff $staff) => ['id'=>$staff->id,'staff_number'=>$staff->staff_number,'full_name'=>$staff->full_name,'department_id'=>$staff->currentEmployment?->department_id,'department'=>$staff->currentEmployment?->department?->name,'rank'=>$staff->currentEmployment?->rank?->name])]]); }
    public function update(UpdateWorkplanRequest $request, Workplan $workplan, WorkplanService $service): WorkplanDetailResource { $this->authorize('update', $workplan); return new WorkplanDetailResource($service->update($workplan, $request->validated(), $request->user())); }
}
