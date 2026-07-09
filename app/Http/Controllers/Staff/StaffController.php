<?php

namespace App\Http\Controllers\Staff;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffAllowanceService;
use App\Domain\Staff\Services\StaffQueryService;
use App\Domain\Staff\Services\StaffSalaryPlacementService;
use App\Domain\Staff\Services\StaffUpdateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffDetailResource;
use App\Http\Resources\StaffResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(Request $request, StaffQueryService $queryService): Response
    {
        $this->authorize('viewAny', Staff::class);

        $filters = $request->only([
            'search',
            'mda_id',
            'department_id',
            'station_id',
            'cadre_id',
            'rank_id',
            'salary_scale_id',
            'level',
            'status',
            'retirement_state',
            'per_page',
        ]);

        $staff = $queryService->paginate($filters, $request->user(), (int) ($filters['per_page'] ?? 15));

        return Inertia::render('Staff/Index', [
            'staff' => StaffResource::collection($staff)->response()->getData(true),
            'filters' => $filters,
            'filterOptions' => $this->buildFilterOptions($request->user()),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Staff::class);

        return Inertia::render('Staff/Create', [
            'formOptions' => $this->buildFilterOptions($request->user()),
        ]);
    }

    public function store(
        StoreStaffRequest $request,
        StaffUpdateService $staffUpdateService,
        StaffSalaryPlacementService $salaryPlacementService,
        StaffAllowanceService $staffAllowanceService,
    ): RedirectResponse {
        $this->authorize('create', Staff::class);

        $validated = $request->validated();
        $this->ensureUserCanAccessMda($request->user(), (int) $validated['mda_id']);

        $staff = Staff::query()->create([
            'mda_id' => $validated['mda_id'],
            'staff_number' => $validated['staff_number'],
            'legacy_cno' => $validated['legacy_cno'] ?? null,
            'legacy_psn' => $validated['legacy_psn'] ?? null,
            'legacy_cno_psn' => ($validated['legacy_cno'] ?? null) && ($validated['legacy_psn'] ?? null)
                ? $validated['legacy_cno'].$validated['legacy_psn']
                : null,
            'surname' => $validated['surname'],
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'full_name' => $validated['full_name'],
            'sex' => $validated['sex'] ?? null,
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'status' => $validated['status'],
        ]);

        $staffUpdateService->updateStaff(
            $staff,
            [],
            $validated['personal_detail'] ?? [],
            [
                'status' => $validated['status'],
                'reason' => 'Created through staff management module',
                'effective_from' => now()->toDateString(),
            ],
        );

        if (! empty($validated['employment']['mda_id'])) {
            $this->ensureUserCanAccessMda($request->user(), (int) $validated['employment']['mda_id']);
            $staffUpdateService->createEmploymentRecord($staff, $validated['employment']);
        }

        if (! empty($validated['salary_placement']['salary_scale_id'])) {
            $salaryPlacement = $validated['salary_placement'];
            $salaryPlacement['salary_scale'] = SalaryScale::query()
                ->forMda((int) $staff->mda_id)
                ->findOrFail((int) $salaryPlacement['salary_scale_id']);
            $salaryPlacementService->createPlacement($staff, $salaryPlacement);
        }

        if (! empty($validated['qualification']['qualification_type_id']) || ! empty($validated['qualification']['qualification_name'])) {
            $staffUpdateService->storeQualification($staff, array_merge($validated['qualification'], [
                'source' => $validated['qualification']['source'] ?? 'staff_management',
            ]));
        }

        if (! empty($validated['allowances'])) {
            $staffAllowanceService->syncAssignments($staff, $validated['allowances']);
        }

        return redirect()->route('staff.show', $staff);
    }

    public function show(Staff $staff): Response
    {
        $this->authorize('view', $staff);

        $staff->load([
            'mda',
            'personalDetail',
            'currentEmployment.mda',
            'currentEmployment.department',
            'currentEmployment.station',
            'currentEmployment.cadre',
            'currentEmployment.rank',
            'currentSalaryPlacement.salaryScale',
            'qualifications.qualificationType',
            'allowanceAssignments.allowanceType',
            'statusHistories',
        ]);

        return Inertia::render('Staff/Show', [
            'staff' => StaffDetailResource::make($staff)->resolve(),
        ]);
    }

    public function edit(Request $request, Staff $staff): Response
    {
        $this->authorize('update', $staff);

        $staff->load([
            'mda',
            'personalDetail',
            'currentEmployment.department',
            'currentEmployment.station',
            'currentEmployment.cadre',
            'currentEmployment.rank',
            'currentSalaryPlacement.salaryScale',
            'qualifications.qualificationType',
            'allowanceAssignments.allowanceType',
            'statusHistories',
        ]);

        return Inertia::render('Staff/Edit', [
            'staff' => StaffDetailResource::make($staff)->resolve(),
            'formOptions' => $this->buildFilterOptions($request->user()),
        ]);
    }

    public function update(UpdateStaffRequest $request, Staff $staff, StaffUpdateService $staffUpdateService): RedirectResponse
    {
        $this->authorize('update', $staff);

        $validated = $request->validated();
        $this->ensureUserCanAccessMda($request->user(), (int) $validated['mda_id']);

        $staffUpdateService->updateStaff(
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

        return redirect()->route('staff.show', $staff);
    }

    public function destroy(Staff $staff): RedirectResponse
    {
        $this->authorize('delete', $staff);

        $staff->delete();

        return redirect()->route('staff.index');
    }

    protected function buildFilterOptions($user): array
    {
        return [
            'mdas' => Mda::query()->visibleToUser($user)->orderBy('name')->get(['id', 'code', 'name'])->toArray(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'mda_id', 'name'])->toArray(),
            'stations' => Station::query()->orderBy('name')->get(['id', 'mda_id', 'name'])->toArray(),
            'cadres' => Cadre::query()->orderBy('name')->get(['id', 'department_id', 'salary_scale_id', 'name'])->toArray(),
            'ranks' => Rank::query()->orderBy('name')->get(['id', 'cadre_id', 'salary_scale_id', 'name', 'level'])->toArray(),
            'salary_scales' => SalaryScale::query()->orderBy('code')->get(['id', 'mda_id', 'code', 'name'])->toArray(),
            'qualification_types' => QualificationType::query()->unified()->orderBy('name')->get(['id', 'code', 'name'])->toArray(),
            'allowance_types' => AllowanceType::query()->orderBy('name')->get(['id', 'mda_id', 'code', 'name'])->toArray(),
            'status_options' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'retired', 'label' => 'Retired'],
                ['value' => 'duplicate', 'label' => 'Duplicate'],
                ['value' => 'inactive', 'label' => 'Inactive'],
            ],
            'retirement_options' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'retired', 'label' => 'Retired'],
            ],
        ];
    }

    protected function ensureUserCanAccessMda($user, int $mdaId): void
    {
        abort_unless($user->canAccessMda($mdaId), 403);
    }
}
