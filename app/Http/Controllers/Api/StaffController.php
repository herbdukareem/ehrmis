<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\PlatformSetting;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffQueryService;
use App\Domain\Staff\Services\StaffSalaryPlacementService;
use App\Domain\Staff\Services\StaffUpdateService;
use App\Domain\Staff\Services\StaffAllowanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ResolveStaffFlaggedIssueRequest;
use App\Http\Requests\Staff\UpdateStaffAppointmentRequest;
use App\Http\Requests\Staff\UpdateStaffAllowanceAssignmentRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffDetailResource;
use App\Http\Resources\StaffResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

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

        $this->loadStaffRelations($staff);

        return response()->json(['data' => StaffDetailResource::make($staff)->resolve()]);
    }

    public function recordSlip(Staff $staff): Response
    {
        $this->authorize('view', $staff);

        $this->loadStaffRelations($staff);
        $staff->load(['mda.setting.headStaff', 'mda.setting.headRank']);

        $data = StaffDetailResource::make($staff)->resolve(request());
        $setting = $staff->mda?->setting;

        $html = view('pdf.staff-record-slip', [
            'staff' => $data,
            'stateName' => PlatformSetting::query()->value('state_name') ?? 'Niger State',
            'logoData' => $this->embedImage($setting?->logo_path, 'public') ?? $this->embedImage('images/niger-state-logo.jpg', 'public'),
            'signatureData' => $this->embedImage($setting?->signature_path, 'public'),
            'headTitle' => $setting?->head_title,
            'headName' => $setting?->headStaff?->full_name ?? $setting?->headRank?->name,
            'generatedAt' => now(),
        ])->render();

        $filename = 'staff-record-slip-'.($staff->staff_number ?: $staff->id).'.pdf';

        return Pdf::loadHTML($html)->setPaper('a4')->download($filename);
    }

    protected function embedImage(?string $path, string $disk): ?string
    {
        if (! $path || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $mimeType = Storage::disk($disk)->mimeType($path) ?: 'image/png';

        return 'data:'.$mimeType.';base64,'.base64_encode(Storage::disk($disk)->get($path));
    }

    public function update(UpdateStaffRequest $request, Staff $staff, StaffUpdateService $staffUpdateService): JsonResponse
    {
        $validated = $request->validated();
        abort_unless($request->user()->canAccessMda((int) $validated['mda_id']), 403);

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

        $this->loadStaffRelations($staff);

        return response()->json([
            'message' => 'Staff record updated.',
            'data' => StaffDetailResource::make($staff)->resolve(),
        ]);
    }

    public function updateAppointment(
        UpdateStaffAppointmentRequest $request,
        Staff $staff,
        StaffUpdateService $staffUpdateService,
        StaffSalaryPlacementService $staffSalaryPlacementService,
    ): JsonResponse {
        $this->authorize('updateAppointment', $staff);

        $validated = $request->validated();
        $currentEmployment = $staff->currentEmployment()->first();
        $currentPlacement = $staff->currentSalaryPlacement()->first();

        $employmentData = [
            'mda_id' => (int) $staff->mda_id,
            'department_id' => $validated['department_id'] ?? null,
            'station_id' => $validated['station_id'] ?? null,
            'location_name' => $validated['location_name'] ?? null,
            'cadre_id' => $validated['cadre_id'] ?? null,
            'rank_id' => $validated['rank_id'] ?? null,
            'staff_category' => $validated['staff_category'] ?? null,
            'initial_rank' => $validated['initial_rank'] ?? null,
            'date_first_appointment' => $validated['date_first_appointment'] ?? null,
            'date_last_promotion' => $validated['date_last_promotion'] ?? null,
            'expected_retirement_date' => $validated['expected_retirement_date'] ?? null,
            'next_promotion_date' => $validated['next_promotion_date'] ?? null,
            'employment_status' => $validated['employment_status'],
            'effective_from' => $validated['effective_from'] ?? now()->toDateString(),
        ];

        $this->assertAppointmentReferencesBelongToStaffMda($staff, $employmentData, $validated);
        $employmentChanged = $this->employmentDataHasChanges($currentEmployment, $employmentData);
        $placementChanged = $this->placementDataHasChanges($currentPlacement, $validated);

        if (! $employmentChanged && ! $placementChanged) {
            $this->loadStaffRelations($staff);

            return response()->json([
                'message' => 'No appointment changes were detected.',
                'data' => StaffDetailResource::make($staff)->resolve(),
            ]);
        }

        DB::transaction(function () use (
            $employmentChanged,
            $placementChanged,
            $staff,
            $employmentData,
            $validated,
            $staffUpdateService,
            $staffSalaryPlacementService
        ): void {
            if ($employmentChanged) {
                $staffUpdateService->createEmploymentRecord($staff, $employmentData);
            }

            if ($placementChanged) {
                $validated['salary_scale'] = SalaryScale::query()
                    ->findOrFail((int) $validated['salary_scale_id']);

                $staffSalaryPlacementService->createPlacement($staff, [
                    'salary_scale' => $validated['salary_scale'],
                    'level' => (int) $validated['level'],
                    'step' => (int) $validated['step'],
                    'effective_from' => $validated['effective_from'] ?? now()->toDateString(),
                    'source' => 'staff_management.appointment',
                ]);
            }
        });

        $staff->refresh();
        $this->loadStaffRelations($staff);

        return response()->json([
            'message' => 'Current appointment updated.',
            'data' => StaffDetailResource::make($staff)->resolve(),
        ]);
    }

    public function updateAllowances(
        UpdateStaffAllowanceAssignmentRequest $request,
        Staff $staff,
        StaffAllowanceService $staffAllowanceService,
    ): JsonResponse {
        $this->authorize('updateAllowances', $staff);

        $assignments = collect($request->validated()['assignments'])
            ->map(fn (array $assignment): array => [
                'allowance_type_id' => $assignment['allowance_type_id'],
                'is_eligible' => (bool) ($assignment['is_eligible'] ?? false),
                'source' => 'staff_management',
                'effective_from' => now()->toDateString(),
            ])
            ->all();

        $staffAllowanceService->syncAssignments($staff, $assignments);

        $this->loadStaffRelations($staff);

        return response()->json([
            'message' => 'Allowance eligibility and gross pay updated.',
            'data' => StaffDetailResource::make($staff)->resolve(),
        ]);
    }

    public function flaggedIssues(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Staff::class);
        $user = $request->user();
        $flaggedFields = ['cadre', 'rank', 'qualification', 'call_allowance'];

        $unresolvedErrors = function ($query) use ($flaggedFields) {
            $query->whereNull('resolved_at')->whereNull('ignored_at')->whereIn('field', $flaggedFields);
        };

        $staff = Staff::query()
            ->whereHas('importRows.errors', $unresolvedErrors)
            ->with(['mda', 'importRows.errors' => $unresolvedErrors])
            ->limit(100)
            ->get();

        $data = $staff->map(fn (Staff $record): array => [
            'id' => $record->id,
            'staff_number' => $record->staff_number,
            'full_name' => $record->full_name,
            'mda' => $record->mda?->name,
            'issues' => $record->importRows
                ->flatMap(fn ($row) => $row->errors)
                ->map(fn ($error): array => [
                    'field' => $error->field,
                    'message' => $error->message,
                    'severity' => $error->severity,
                ])
                ->unique(fn (array $issue): string => $issue['field'].'|'.$issue['message'])
                ->values(),
        ]);

        return response()->json(['data' => $data]);
    }

    public function resolveFlaggedIssue(
        ResolveStaffFlaggedIssueRequest $request,
        Staff $staff,
        StaffUpdateService $staffUpdateService,
    ): JsonResponse {
        $validated = $request->validated();

        $staff = $staffUpdateService->resolveFlaggedIssues($staff, [
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'cadre_id' => isset($validated['cadre_id']) ? (int) $validated['cadre_id'] : null,
            'rank_id' => isset($validated['rank_id']) ? (int) $validated['rank_id'] : null,
            'qualification_type_id' => isset($validated['qualification_type_id']) ? (int) $validated['qualification_type_id'] : null,
            'allowances' => $validated['allowances'] ?? null,
        ], $request->user());

        $staff->load(['qualifications.qualificationType', 'allowanceAssignments.allowanceType', 'documents.pages']);

        return response()->json([
            'message' => 'Staff record updated and flagged issues resolved.',
            'data' => StaffDetailResource::make($staff)->resolve(),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Staff::class);
        $user = $request->user();
        $departments = Department::query()
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
                'stations' => Station::query()->orderBy('name')->get(['id', 'mda_id', 'name']),
                'cadres' => $cadres,
                'ranks' => Rank::query()->whereIn('cadre_id', $cadres->pluck('id'))->orderBy('name')->get(['id', 'cadre_id', 'salary_scale_id', 'name', 'level']),
                'salary_scales' => SalaryScale::query()->orderBy('code')->get(['id', 'code', 'name']),
                'qualification_types' => QualificationType::query()->unified()->orderBy('name')->get(['id', 'code', 'name']),
                'allowance_types' => AllowanceType::query()->orderBy('name')->get(['id', 'code', 'name']),
                'statuses' => ['active', 'retired', 'duplicate', 'inactive'],
            ],
        ]);
    }

    protected function loadStaffRelations(Staff $staff): void
    {
        $staff->load([
            'mda.setting',
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
            'documents.pages',
        ]);
    }

    /**
     * @param  array<string, mixed>  $employmentData
     * @param  array<string, mixed>  $validated
     */
    protected function assertAppointmentReferencesBelongToStaffMda(Staff $staff, array $employmentData, array $validated): void
    {
        $mdaId = (int) $staff->mda_id;

        if (! empty($employmentData['department_id']) && ! Department::query()->forMda($mdaId)->whereKey($employmentData['department_id'])->exists()) {
            throw ValidationException::withMessages([
                'department_id' => 'The selected department is not available for this MDA.',
            ]);
        }

        if (! empty($employmentData['station_id']) && ! Station::query()->forMda($mdaId)->whereKey($employmentData['station_id'])->exists()) {
            throw ValidationException::withMessages([
                'station_id' => 'The selected station is not available for this MDA.',
            ]);
        }

        $cadreId = $employmentData['cadre_id'] ?? null;

        if ($cadreId && ! Cadre::query()->whereKey($cadreId)->whereHas('department', fn ($query) => $query->forMda($mdaId))->exists()) {
            throw ValidationException::withMessages([
                'cadre_id' => 'The selected cadre is not available for this MDA.',
            ]);
        }

        if (! empty($employmentData['rank_id'])) {
            $rankQuery = Rank::query()
                ->whereKey($employmentData['rank_id'])
                ->whereHas('cadre.department', fn ($query) => $query->forMda($mdaId));

            if ($cadreId) {
                $rankQuery->where('cadre_id', $cadreId);
            }

            if (! $rankQuery->exists()) {
                throw ValidationException::withMessages([
                    'rank_id' => 'The selected rank is not available for the chosen cadre and MDA.',
                ]);
            }
        }

        if (! empty($validated['salary_scale_id']) && ! SalaryScale::query()->whereKey($validated['salary_scale_id'])->exists()) {
            throw ValidationException::withMessages([
                'salary_scale_id' => 'The selected salary scale does not exist.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $employmentData
     */
    protected function employmentDataHasChanges($currentEmployment, array $employmentData): bool
    {
        if (! $currentEmployment) {
            return true;
        }

        $fields = [
            'mda_id',
            'department_id',
            'station_id',
            'location_name',
            'cadre_id',
            'rank_id',
            'staff_category',
            'initial_rank',
            'date_first_appointment',
            'date_last_promotion',
            'expected_retirement_date',
            'next_promotion_date',
            'employment_status',
        ];

        foreach ($fields as $field) {
            $currentValue = $currentEmployment->{$field};
            $nextValue = $employmentData[$field] ?? null;

            if (is_object($currentValue) && method_exists($currentValue, 'toDateString')) {
                $currentValue = $currentValue->toDateString();
            }

            if ((string) ($currentValue ?? '') !== (string) ($nextValue ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function placementDataHasChanges($currentPlacement, array $validated): bool
    {
        if (! isset($validated['salary_scale_id'], $validated['level'], $validated['step'])) {
            return false;
        }

        if (! $currentPlacement) {
            return true;
        }

        return (int) $currentPlacement->salary_scale_id !== (int) $validated['salary_scale_id']
            || (int) $currentPlacement->level !== (int) $validated['level']
            || (int) $currentPlacement->step !== (int) $validated['step'];
    }
}
