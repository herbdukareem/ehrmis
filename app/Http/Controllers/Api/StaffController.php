<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffQueryService;
use App\Domain\Staff\Services\StaffUpdateService;
use App\Domain\Staff\Services\StaffAllowanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateStaffAllowanceAssignmentRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffDetailResource;
use App\Http\Resources\StaffResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function index(Request $request, StaffQueryService $queryService): JsonResponse
    {
        $this->authorize('viewAny', Staff::class);

        $filters = $request->only([
            'search', 'cno', 'psn', 'mda_id', 'department_id', 'station_id', 'cadre_id',
            'rank_id', 'salary_scale_id', 'level', 'status', 'retirement_state', 'per_page',
        ]);

        $staff = $queryService->paginate($filters, $request->user(), (int) ($filters['per_page'] ?? 20));

        return response()->json([
            'data' => StaffResource::collection($staff->items())->resolve(),
            'meta' => [
                'current_page' => $staff->currentPage(),
                'last_page' => $staff->lastPage(),
                'per_page' => $staff->perPage(),
                'total' => $staff->total(),
            ],
        ]);
    }

    public function show(Staff $staff): JsonResponse
    {
        $this->authorize('view', $staff);

        $staff->load([
            'mda', 'personalDetail', 'currentEmployment.mda', 'currentEmployment.department',
            'currentEmployment.station', 'currentEmployment.cadre', 'currentEmployment.rank',
            'currentSalaryPlacement.salaryScale', 'qualifications.qualificationType',
            'allowanceAssignments.allowanceType', 'statusHistories',
            'documents.pages',
        ]);

        return response()->json(['data' => StaffDetailResource::make($staff)->resolve()]);
    }

    public function update(UpdateStaffRequest $request, Staff $staff, StaffUpdateService $staffUpdateService): JsonResponse
    {
        $validated = $request->validated();
        abort_unless($request->user()->hasGlobalMdaAccess() || (int) $request->user()->mda_id === (int) $validated['mda_id'], 403);

        $staff = $staffUpdateService->updateStaff(
            $staff,
            [
                'mda_id' => $validated['mda_id'],
                'staff_number' => $validated['staff_number'],
                'legacy_cno' => $validated['legacy_cno'] ?? null,
                'legacy_psn' => $validated['legacy_psn'] ?? null,
                'surname' => $validated['surname'],
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'full_name' => $validated['full_name'],
                'sex' => $validated['sex'] ?? null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'status' => $validated['status'],
            ],
            $validated['personal_detail'] ?? [],
            [
                'status' => $validated['status'],
                'reason' => $validated['status_reason'] ?? 'Updated through staff management module',
                'effective_from' => $validated['status_effective_from'] ?? now()->toDateString(),
            ],
        );

        $staff->load([
            'mda', 'personalDetail', 'currentEmployment.mda', 'currentEmployment.department',
            'currentEmployment.station', 'currentEmployment.cadre', 'currentEmployment.rank',
            'currentSalaryPlacement.salaryScale', 'qualifications.qualificationType',
            'allowanceAssignments.allowanceType', 'statusHistories',
            'documents.pages',
        ]);

        return response()->json([
            'message' => 'Staff record updated.',
            'data' => StaffDetailResource::make($staff)->resolve(),
        ]);
    }

    public function updateAllowances(
        UpdateStaffAllowanceAssignmentRequest $request,
        Staff $staff,
        StaffAllowanceService $staffAllowanceService,
    ): JsonResponse {
        $assignments = collect($request->validated()['assignments'])
            ->map(fn (array $assignment): array => [
                'allowance_type_id' => $assignment['allowance_type_id'],
                'is_eligible' => (bool) ($assignment['is_eligible'] ?? false),
                'source' => 'staff_management',
                'effective_from' => now()->toDateString(),
            ])
            ->all();

        $staffAllowanceService->syncAssignments($staff, $assignments);

        $staff->load([
            'mda', 'personalDetail', 'currentEmployment.mda', 'currentEmployment.department',
            'currentEmployment.station', 'currentEmployment.cadre', 'currentEmployment.rank',
            'currentSalaryPlacement.salaryScale', 'qualifications.qualificationType',
            'allowanceAssignments.allowanceType', 'statusHistories',
            'documents.pages',
        ]);

        return response()->json([
            'message' => 'Allowance eligibility and gross pay updated.',
            'data' => StaffDetailResource::make($staff)->resolve(),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Staff::class);
        $user = $request->user();
        $departments = Department::query()
            ->when(! $user->hasGlobalMdaAccess(), fn ($query) => $query->where('mda_id', $user->mda_id))
            ->orderBy('name')
            ->get(['id', 'mda_id', 'name']);
        $cadres = Cadre::query()
            ->whereIn('department_id', $departments->pluck('id'))
            ->orderBy('name')
            ->get(['id', 'department_id', 'salary_scale_id', 'name']);

        return response()->json([
            'data' => [
                'mdas' => Mda::query()->visibleToUser($user)->orderBy('name')->get(['id', 'code', 'name']),
                'departments' => $departments,
                'stations' => Station::query()->when(! $user->hasGlobalMdaAccess(), fn ($query) => $query->where('mda_id', $user->mda_id))->orderBy('name')->get(['id', 'mda_id', 'name']),
                'cadres' => $cadres,
                'ranks' => Rank::query()->whereIn('cadre_id', $cadres->pluck('id'))->orderBy('name')->get(['id', 'cadre_id', 'salary_scale_id', 'name', 'level']),
                'salary_scales' => SalaryScale::query()->orderBy('code')->get(['id', 'code', 'name']),
                'statuses' => ['active', 'retired', 'duplicate', 'inactive'],
            ],
        ]);
    }
}
