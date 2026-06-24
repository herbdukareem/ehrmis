<?php

namespace App\Http\Controllers\Api;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Domain\Staff\Models\StaffStatusHistory;
use App\Domain\Staff\Services\StaffRetirementService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected StaffRetirementService $staffRetirementService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = fn (Builder $query, string $column = 'mda_id'): Builder => $user->scopeToAccessibleMdas($query, $column);

        $staffQuery = Staff::query();
        $today = CarbonImmutable::today();

        $movementQuery = MovementWorkbook::query();
        $scope($movementQuery);

        $budgetQuery = BudgetWorkbook::query();
        $scope($budgetQuery);

        $importQuery = LegacyStaffImportBatch::query()
            ->when(! $user->hasGlobalMdaAccess(), fn (Builder $query) => $query->whereHas(
                'rows',
                fn (Builder $rows) => $scope($rows)
            ));

        return response()->json([
            'data' => [
                'counts' => [
                    'staff' => $staffQuery->count(),
                    'active_staff' => $this->activeStaffCount($user),
                    'retired_staff' => $this->retiredStaffCount($user),
                    'other_staff' => (clone $staffQuery)->whereNotIn('status', ['active', 'retired'])->count(),
                    'import_batches' => $importQuery->count(),
                    'movement_workbooks' => $movementQuery->count(),
                    'budget_workbooks' => $budgetQuery->count(),
                ],
                'retirement_windows' => [
                    'this_month' => $this->retirementCount($user, $today->startOfMonth(), $today->endOfMonth()),
                    'next_month' => $this->retirementCount($user, $today->addMonthNoOverflow()->startOfMonth(), $today->addMonthNoOverflow()->endOfMonth()),
                    'this_year' => $this->retirementCount($user, $today->startOfYear(), $today->endOfYear()),
                    'next_year' => $this->retirementCount($user, $today->addYear()->startOfYear(), $today->addYear()->endOfYear()),
                ],
                'distributions' => [
                    'departments' => $this->departmentDistribution($user),
                    'salary_scales' => $this->salaryScaleDistribution($user),
                    'gender' => $this->genderDistribution($staffQuery),
                    'cadres' => $this->cadreDistribution($user),
                ],
                'retirement_trends' => [
                    'projection' => $this->retirementProjection($user, $today),
                    'history' => $this->retirementHistory($user, $today),
                ],
                'attention' => [
                    'imports_pending_approval' => (clone $importQuery)->whereIn('status', ['submitted', 'under_review'])->count(),
                    'movement_drafts' => (clone $movementQuery)->whereIn('status', ['draft', 'reviewed', 'rejected'])->count(),
                    'budget_drafts' => (clone $budgetQuery)->whereIn('status', ['draft', 'submitted', 'rejected'])->count(),
                ],
                'recent_imports' => $importQuery->latest('id')->limit(5)->get(['id', 'source_table', 'status', 'completed_at']),
            ],
        ]);
    }

    protected function retirementCount($user, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->employmentQuery($user)
            ->where('staff.status', '!=', 'retired')
            ->where('staff_employments.employment_status', '!=', 'retired')
            ->whereBetween('staff_employments.expected_retirement_date', [$from->toDateString(), $to->toDateString()])
            ->count();
    }

    protected function retiredStaffCount($user): int
    {
        $today = CarbonImmutable::today()->toDateString();

        return $this->employmentQuery($user)
            ->where(function (Builder $query) use ($today): void {
                $query
                    ->where('staff.status', 'retired')
                    ->orWhere('staff_employments.employment_status', 'retired')
                    ->orWhereDate('staff_employments.expected_retirement_date', '<=', $today);
            })
            ->count();
    }

    protected function activeStaffCount($user): int
    {
        $today = CarbonImmutable::today()->toDateString();

        return $this->employmentQuery($user)
            ->where('staff.status', 'active')
            ->where('staff_employments.employment_status', '!=', 'retired')
            ->where(function (Builder $query) use ($today): void {
                $query
                    ->whereNull('staff_employments.expected_retirement_date')
                    ->orWhereDate('staff_employments.expected_retirement_date', '>', $today);
            })
            ->count();
    }

    protected function departmentDistribution($user): array
    {
        return $this->employmentQuery($user)
            ->leftJoin('departments', 'departments.id', '=', 'staff_employments.department_id')
            ->selectRaw("COALESCE(departments.name, 'Unassigned') as label, COUNT(*) as total")
            ->groupBy('departments.id', 'departments.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'total' => (int) $row->total])
            ->all();
    }

    protected function salaryScaleDistribution($user): array
    {
        return $this->salaryPlacementQuery($user)
            ->leftJoin('salary_scales', 'salary_scales.id', '=', 'staff_salary_placements.salary_scale_id')
            ->selectRaw("COALESCE(salary_scales.code, 'Unassigned') as label, COUNT(*) as total")
            ->groupBy('salary_scales.id', 'salary_scales.code')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'total' => (int) $row->total])
            ->all();
    }

    protected function genderDistribution(Builder $staffQuery): array
    {
        return (clone $staffQuery)
            ->selectRaw("COALESCE(sex, 'Not recorded') as label, COUNT(*) as total")
            ->groupBy('sex')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['label' => ucfirst($row->label), 'total' => (int) $row->total])
            ->all();
    }

    protected function cadreDistribution($user): array
    {
        $cadres = $this->employmentQuery($user)
            ->leftJoin('cadres', 'cadres.id', '=', 'staff_employments.cadre_id')
            ->selectRaw("cadres.id, COALESCE(cadres.name, 'Unassigned') as name, COUNT(*) as staff_count")
            ->groupBy('cadres.id', 'cadres.name')
            ->orderByDesc('staff_count')
            ->get();

        $allowances = StaffAllowanceAssignment::query()
            ->join('staff_employments', function ($join): void {
                $join->on('staff_employments.staff_id', '=', 'staff_allowance_assignments.staff_id')
                    ->where('staff_employments.is_current', true);
            })
            ->join('staff', 'staff.id', '=', 'staff_allowance_assignments.staff_id')
            ->join('allowance_types', 'allowance_types.id', '=', 'staff_allowance_assignments.allowance_type_id')
            ->whereNull('staff.deleted_at')
            ->where('staff_allowance_assignments.is_eligible', true)
            ->where(function ($query): void {
                $query->whereNull('staff_allowance_assignments.effective_from')
                    ->orWhereDate('staff_allowance_assignments.effective_from', '<=', today());
            })
            ->where(function ($query): void {
                $query->whereNull('staff_allowance_assignments.effective_to')
                    ->orWhereDate('staff_allowance_assignments.effective_to', '>=', today());
            })
            ->selectRaw('staff_employments.cadre_id, allowance_types.code, allowance_types.name, COUNT(DISTINCT staff_allowance_assignments.staff_id) as total')
            ->groupBy('staff_employments.cadre_id', 'allowance_types.id', 'allowance_types.code', 'allowance_types.name');

        $user->scopeToAccessibleMdas($allowances, 'staff.mda_id');

        $allowances = $allowances
            ->get()
            ->groupBy(fn ($row) => (string) ($row->cadre_id ?? 'unassigned'));

        return $cadres->map(function ($cadre) use ($allowances): array {
            $key = (string) ($cadre->id ?? 'unassigned');

            return [
                'id' => $cadre->id,
                'name' => $cadre->name,
                'staff_count' => (int) $cadre->staff_count,
                'allowances' => $allowances->get($key, collect())->map(fn ($allowance): array => [
                    'code' => $allowance->code,
                    'name' => $allowance->name,
                    'total' => (int) $allowance->total,
                ])->values()->all(),
            ];
        })->all();
    }

    protected function retirementProjection($user, CarbonImmutable $today): array
    {
        return collect(range(0, 4))->map(function (int $offset) use ($user, $today): array {
            $year = $today->year + $offset;

            return [
                'label' => (string) $year,
                'total' => $this->retirementCount($user, CarbonImmutable::create($year)->startOfYear(), CarbonImmutable::create($year)->endOfYear()),
            ];
        })->all();
    }

    protected function retirementHistory($user, CarbonImmutable $today): array
    {
        return collect(range(5, 1))->map(function (int $offset) use ($user, $today): array {
            $year = $today->year - $offset;
            $yearStart = CarbonImmutable::create($year)->startOfYear()->toDateString();
            $yearEnd = CarbonImmutable::create($year)->endOfYear()->toDateString();

            $retiredHistory = StaffStatusHistory::query()
                ->selectRaw('staff_id, MIN(effective_from) as retired_effective_from')
                ->where('status', 'retired')
                ->groupBy('staff_id');

            $total = StaffEmployment::query()
                ->join('staff', 'staff.id', '=', 'staff_employments.staff_id')
                ->leftJoinSub($retiredHistory, 'retired_history', function ($join): void {
                    $join->on('retired_history.staff_id', '=', 'staff.id');
                })
                ->whereNull('staff.deleted_at')
                ->where('staff_employments.is_current', true)
                ->where(function (Builder $query) use ($yearStart, $yearEnd): void {
                    $query
                        ->whereBetween('retired_history.retired_effective_from', [$yearStart, $yearEnd])
                        ->orWhere(function (Builder $fallbackQuery) use ($yearStart, $yearEnd): void {
                            $fallbackQuery
                                ->whereNull('retired_history.retired_effective_from')
                                ->whereBetween('staff_employments.expected_retirement_date', [$yearStart, $yearEnd]);
                        });
                })
                ->distinct('staff.id');

            $user->scopeToAccessibleMdas($total, 'staff.mda_id');
            $total = $total->count('staff.id');

            return ['label' => (string) $year, 'total' => $total];
        })->all();
    }

    protected function employmentQuery($user)
    {
        $query = StaffEmployment::query()
            ->join('staff', 'staff.id', '=', 'staff_employments.staff_id')
            ->whereNull('staff.deleted_at')
            ->where('staff_employments.is_current', true);

        $user->scopeToAccessibleMdas($query, 'staff.mda_id');

        return $query;
    }

    protected function salaryPlacementQuery($user)
    {
        $query = StaffSalaryPlacement::query()
            ->join('staff', 'staff.id', '=', 'staff_salary_placements.staff_id')
            ->whereNull('staff.deleted_at')
            ->where('staff_salary_placements.is_current', true);

        $user->scopeToAccessibleMdas($query, 'staff.mda_id');

        return $query;
    }
}
