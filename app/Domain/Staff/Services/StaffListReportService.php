<?php

namespace App\Domain\Staff\Services;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use App\Support\ReportFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StaffListReportService
{
    public const DATE_COLUMNS = ['date_of_birth', 'date_first_appointment', 'date_last_promotion', 'next_promotion_date', 'expected_retirement_date'];

    public const COLUMNS = [
        'staff_number' => 'Staff number', 'cno' => 'CNO', 'psn' => 'PSN',
        'full_name' => 'Full name', 'sex' => 'Sex', 'date_of_birth' => 'Date of birth',
        'phone' => 'Phone', 'email' => 'Email', 'state_of_origin' => 'State of origin',
        'lga' => 'LGA', 'status' => 'Staff status', 'mda' => 'MDA',
        'department' => 'Department', 'station' => 'Station', 'cadre' => 'Cadre', 'rank' => 'Rank',
        'staff_category' => 'Staff category', 'initial_rank' => 'Initial rank',
        'date_first_appointment' => 'First appointment', 'date_last_promotion' => 'Last promotion',
        'next_promotion_date' => 'Next promotion', 'expected_retirement_date' => 'Expected retirement',
        'employment_status' => 'Employment status', 'salary_scale' => 'Salary scale',
        'level' => 'Level', 'step' => 'Step', 'basic_salary' => 'Basic salary (NGN)',
        'allowance_total' => 'Eligible allowances total (NGN)', 'gross_salary' => 'Calculated gross (NGN)',
    ];

    public function __construct(
        protected StaffQueryService $staffQueryService,
        protected SalaryCalculationService $salaryCalculationService,
    ) {}

    public function query(User $user, array $filters): Builder
    {
        $today = today()->toDateString();
        $query = Staff::query()->with([
            'mda', 'personalDetail', 'currentEmployment.department',
            'currentEmployment.station', 'currentEmployment.cadre',
            'currentEmployment.rank', 'currentSalaryPlacement.salaryScale',
            'allowanceAssignments' => fn ($query) => $query
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
                // Match StaffAllowanceService: current manual decisions override imports.
                ->orderByRaw("CASE WHEN source = 'staff_management' THEN 1 ELSE 0 END")
                ->orderBy('id'),
        ]);
        $user->scopeToAccessibleStaff($query);
        if ($user->hasStationScope()) {
            $query->whereHas('currentEmployment', fn ($q) => $user->scopeToAccessibleStations($q));
        }
        $this->staffQueryService->applyFilters($query, $filters, $user);

        return $query->orderBy('full_name')->orderBy('id');
    }

    public function allowanceTypes(): Collection
    {
        return AllowanceType::query()->orderBy('name')->get(['id', 'code', 'name']);
    }

    public function options(User $user): array
    {
        $departments = Department::query()
            ->tap(fn ($q) => $user->scopeToAccessibleMdas($q))
            ->tap(fn ($q) => $user->scopeToAccessibleDepartments($q, 'id'))
            ->orderBy('name')->get(['id', 'mda_id', 'name']);

        return [
            'mdas' => Mda::query()->visibleToUser($user)->orderBy('name')->get(['id', 'name', 'code']),
            'departments' => $departments,
            'stations' => Station::query()
                ->tap(fn ($q) => $user->scopeToAccessibleMdas($q))
                ->tap(fn ($q) => $user->scopeToAccessibleStations($q, 'id'))
                ->orderBy('name')->get(['id', 'mda_id', 'name']),
            'cadres' => Cadre::query()->whereIn('department_id', $departments->pluck('id'))
                ->orderBy('name')->get(['id', 'department_id', 'name']),
            'salary_scales' => SalaryScale::query()->orderBy('code')->get(['id', 'code', 'name']),
            'statuses' => ['active', 'retired', 'inactive', 'duplicate'],
        ];
    }

    public function row(Staff $staff, Collection $allowanceTypes): array
    {
        $employment = $staff->currentEmployment;
        $placement = $staff->currentSalaryPlacement;
        $personal = $staff->personalDetail;
        $assignments = $staff->allowanceAssignments->keyBy('allowance_type_id');
        $rate = $placement?->salaryScale
            ? $this->salaryCalculationService->getRate($placement->salaryScale->code, $placement->level, $placement->step, $staff->mda_id)
            : null;
        $rateAllowances = $rate?->rateAllowances->keyBy('allowance_type_id') ?? collect();
        $allowances = $allowanceTypes->map(function (AllowanceType $type) use ($assignments, $rateAllowances): array {
            $assignment = $assignments->get($type->id);
            $eligible = $assignment?->is_eligible;
            $amount = $eligible === false ? 0.0 : null;
            if ($eligible && $rateAllowances->has($type->id)) {
                $amount = round((float) $rateAllowances->get($type->id)->amount, 2);
            }

            return [
                'id' => $type->id, 'name' => $type->name, 'code' => $type->code,
                'eligibility' => $eligible === null ? 'Not assigned' : ($eligible ? 'Eligible' : 'Not eligible'),
                'amount' => $amount,
            ];
        });
        $eligible = $allowances->where('eligibility', 'Eligible');
        $unpricedAllowances = $eligible->filter(fn ($item) => $item['amount'] === null)->pluck('name')->values()->all();
        // Keep known payments in the totals; report unpriced eligible allowances separately.
        $total = $rate ? round($eligible->sum('amount'), 2) : null;
        $basic = $rate ? round((float) $rate->basic_salary, 2) : null;

        return [
            'id' => $staff->id,
            'staff_number' => $staff->staff_number,
            'cno' => ReportFormatter::cno($staff->legacy_cno, $staff->staff_number),
            'psn' => $staff->legacy_psn,
            'full_name' => $staff->full_name,
            'sex' => $staff->sex,
            'date_of_birth' => $staff->date_of_birth?->toDateString(),
            'phone' => $personal?->phone,
            'email' => $personal?->email,
            'state_of_origin' => $personal?->state_of_origin,
            'lga' => $personal?->lga,
            'status' => $staff->status,
            'mda' => $staff->mda?->name,
            'department' => $employment?->department?->name,
            'station' => $employment?->station?->name,
            'cadre' => $employment?->cadre?->name,
            'rank' => $employment?->rank?->name,
            'staff_category' => $employment?->staff_category,
            'initial_rank' => $employment?->initial_rank,
            'date_first_appointment' => $employment?->date_first_appointment?->toDateString(),
            'date_last_promotion' => $employment?->date_last_promotion?->toDateString(),
            'next_promotion_date' => $employment?->next_promotion_date?->toDateString(),
            'expected_retirement_date' => $employment?->expected_retirement_date?->toDateString(),
            'employment_status' => $employment?->employment_status,
            'salary_scale' => $placement?->salaryScale?->code,
            'level' => $placement?->level,
            'step' => $placement?->step,
            'basic_salary' => $basic,
            'allowance_total' => $total,
            'gross_salary' => $basic !== null && $total !== null ? round($basic + $total, 2) : null,
            'unpriced_allowances' => $unpricedAllowances,
            'allowances' => $allowances->all(),
        ];
    }
}
