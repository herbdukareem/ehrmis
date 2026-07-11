<?php

namespace App\Domain\Dashboard\Services;

use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ExecutiveDashboardService
{
    public function data(User $user, array $filters = []): array
    {
        $today = CarbonImmutable::today();
        $year = (int) ($filters['year'] ?? $today->year);
        $filters = $this->normalizeFilters($user, $filters, $year);
        $monthlyWageBill = $this->currentMonthlyWageBill($user, $filters);
        $annualWageBill = $monthlyWageBill * 12;
        $projectedAnnualWageBill = $this->projectAnnualWageBill($annualWageBill, $year, $today->year);
        $reporting = $this->reportingCompliance($user, $filters, $year);

        return [
            'filters' => [
                'year' => $year,
                'mda_id' => $filters['mda_id'],
                'station_id' => $filters['station_id'],
                'cadre_id' => $filters['cadre_id'],
                'lga' => $filters['lga'],
            ],
            'options' => $this->options($user, $year),
            'kpis' => [
                'total_staff' => $this->staffCount($user, $filters),
                'active_staff' => $this->activeStaffCount($user, $filters, $today),
                'retiring_this_year' => $this->retiringBetween($user, $filters, CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31)),
                'retiring_in_three_years' => $this->retiringBetween($user, $filters, $today, $today->addYears(3)->endOfYear()),
                'current_monthly_wage_bill' => round($monthlyWageBill, 2),
                'current_annual_wage_bill' => round($annualWageBill, 2),
                'projected_annual_wage_bill' => round($projectedAnnualWageBill, 2),
                'mdas_reporting' => [
                    'reported' => $reporting['mdas_reported'],
                    'total' => $reporting['mdas_total'],
                ],
            ],
            'retirement' => [
                'trend' => $this->retirementTrend($user, $filters, $year),
                'projection_by_cadre' => $this->retirementProjectionByCadre($user, $filters, $year),
                'attrition_breakdown' => $this->attritionBreakdown($user, $filters, $today),
            ],
            'wage_bill' => [
                'monthly_trend' => $this->wageBillTrend($monthlyWageBill, $today),
                'five_year_projection' => $this->fiveYearWageProjection($annualWageBill, max($year, $today->year)),
                'by_mda' => $this->wageBillByMda($user, $filters),
                'insights' => $this->wageInsights($annualWageBill, $projectedAnnualWageBill, $user, $filters, $year),
            ],
            'workforce' => [
                'by_cadre' => $this->staffByCadre($user, $filters),
                'age_bands' => $this->ageBands($user, $filters, $today),
            ],
            'service_reporting' => [
                'compliance_percent' => $reporting['compliance_percent'],
                'facilities_submitted' => $reporting['facilities_submitted'],
                'facilities_total' => $reporting['facilities_total'],
                'pending_returns' => $reporting['pending_returns'],
                'late_submissions' => $reporting['late_submissions'],
                'trend' => $reporting['trend'],
            ],
            'alerts' => $this->alerts($user, $filters, $year, $annualWageBill, $projectedAnnualWageBill, $reporting),
        ];
    }

    protected function normalizeFilters(User $user, array $filters, int $year): array
    {
        $mdaId = isset($filters['mda_id']) && $filters['mda_id'] !== '' ? (int) $filters['mda_id'] : null;

        if ($mdaId !== null && ! $user->canAccessMda($mdaId)) {
            $mdaId = null;
        }

        return [
            'year' => $year,
            'mda_id' => $mdaId,
            'station_id' => isset($filters['station_id']) && $filters['station_id'] !== '' ? (int) $filters['station_id'] : null,
            'cadre_id' => isset($filters['cadre_id']) && $filters['cadre_id'] !== '' ? (int) $filters['cadre_id'] : null,
            'lga' => trim((string) ($filters['lga'] ?? '')) ?: null,
        ];
    }

    protected function options(User $user, int $year): array
    {
        $visibleMdas = Mda::query()
            ->visibleToUser($user)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $stations = Station::query()
            ->forMdas($visibleMdas->pluck('id')->all())
            ->orderBy('name')
            ->get(['id', 'mda_id', 'code', 'name']);

        $cadres = Cadre::query()
            ->visibleToUser($user)
            ->orderBy('name')
            ->get(['id', 'department_id', 'name']);

        $lgas = $this->staffBaseQuery($user, [])
            ->join('staff_personal_details', 'staff_personal_details.staff_id', '=', 'staff.id')
            ->whereNotNull('staff_personal_details.lga')
            ->distinct()
            ->orderBy('staff_personal_details.lga')
            ->pluck('staff_personal_details.lga')
            ->values()
            ->all();

        return [
            'years' => range($year - 2, $year + 5),
            'mdas' => $visibleMdas->values()->all(),
            'stations' => $stations->values()->all(),
            'cadres' => $cadres->values()->all(),
            'lgas' => $lgas,
        ];
    }

    protected function staffBaseQuery(User $user, array $filters): Builder
    {
        $query = DB::table('staff')
            ->leftJoin('staff_employments', function ($join): void {
                $join->on('staff_employments.staff_id', '=', 'staff.id')
                    ->where('staff_employments.is_current', true);
            })
            ->whereNull('staff.deleted_at');

        if (! $user->hasGlobalMdaAccess()) {
            $accessibleMdaIds = $user->accessibleMdaIds()->all();

            if ($accessibleMdaIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('staff.mda_id', $accessibleMdaIds);
            }
        }

        return $this->applyStaffFilters($query, $filters);
    }

    protected function applyStaffFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['mda_id'] ?? null, fn (Builder $builder, int $mdaId): Builder => $builder->where('staff.mda_id', $mdaId))
            ->when($filters['station_id'] ?? null, fn (Builder $builder, int $stationId): Builder => $builder->where('staff_employments.station_id', $stationId))
            ->when($filters['cadre_id'] ?? null, fn (Builder $builder, int $cadreId): Builder => $builder->where('staff_employments.cadre_id', $cadreId))
            ->when($filters['lga'] ?? null, function (Builder $builder, string $lga): Builder {
                return $builder
                    ->join('staff_personal_details as lga_details', 'lga_details.staff_id', '=', 'staff.id')
                    ->where('lga_details.lga', $lga);
            });
    }

    protected function staffCount(User $user, array $filters): int
    {
        return (int) $this->staffBaseQuery($user, $filters)->distinct('staff.id')->count('staff.id');
    }

    protected function activeStaffCount(User $user, array $filters, CarbonImmutable $today): int
    {
        return (int) $this->staffBaseQuery($user, $filters)
            ->where('staff.status', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('staff_employments.employment_status')
                    ->orWhere('staff_employments.employment_status', '!=', 'retired');
            })
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('staff_employments.expected_retirement_date')
                    ->orWhereDate('staff_employments.expected_retirement_date', '>', $today->toDateString());
            })
            ->distinct('staff.id')
            ->count('staff.id');
    }

    protected function retiringBetween(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $this->staffBaseQuery($user, $filters)
            ->where('staff.status', '!=', 'retired')
            ->where(function (Builder $query): void {
                $query->whereNull('staff_employments.employment_status')
                    ->orWhere('staff_employments.employment_status', '!=', 'retired');
            })
            ->whereBetween('staff_employments.expected_retirement_date', [$from->toDateString(), $to->toDateString()])
            ->distinct('staff.id')
            ->count('staff.id');
    }

    protected function currentMonthlyWageBill(User $user, array $filters): float
    {
        $query = $this->staffBaseQuery($user, $filters)
            ->join('staff_salary_placements', function ($join): void {
                $join->on('staff_salary_placements.staff_id', '=', 'staff.id')
                    ->where('staff_salary_placements.is_current', true);
            });

        return (float) $query->sum(DB::raw('COALESCE(staff_salary_placements.calculated_gross_salary_snapshot, staff_salary_placements.gross_salary, 0)'));
    }

    protected function projectAnnualWageBill(float $annualWageBill, int $targetYear, int $currentYear): float
    {
        $yearsAhead = max(1, $targetYear - $currentYear + 1);

        return $annualWageBill * (1 + (0.072 * $yearsAhead));
    }

    protected function retirementTrend(User $user, array $filters, int $year): array
    {
        return collect(range($year, $year + 4))
            ->map(fn (int $trendYear): array => [
                'label' => (string) $trendYear,
                'total' => $this->retiringBetween($user, $filters, CarbonImmutable::create($trendYear, 1, 1), CarbonImmutable::create($trendYear, 12, 31)),
            ])
            ->values()
            ->all();
    }

    protected function retirementProjectionByCadre(User $user, array $filters, int $year): array
    {
        return $this->staffBaseQuery($user, $filters)
            ->leftJoin('cadres', 'cadres.id', '=', 'staff_employments.cadre_id')
            ->whereBetween('staff_employments.expected_retirement_date', ["{$year}-01-01", ($year + 4).'-12-31'])
            ->selectRaw("COALESCE(cadres.name, 'Unassigned') as label, COUNT(DISTINCT staff.id) as total")
            ->groupBy('cadres.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'total' => (int) $row->total])
            ->all();
    }

    protected function attritionBreakdown(User $user, array $filters, CarbonImmutable $today): array
    {
        $retired = (int) $this->staffBaseQuery($user, $filters)
            ->where(function (Builder $query) use ($today): void {
                $query->where('staff.status', 'retired')
                    ->orWhere('staff_employments.employment_status', 'retired')
                    ->orWhereDate('staff_employments.expected_retirement_date', '<=', $today->toDateString());
            })
            ->distinct('staff.id')
            ->count('staff.id');
        $inactive = (int) $this->staffBaseQuery($user, $filters)
            ->whereNotIn('staff.status', ['active', 'retired'])
            ->distinct('staff.id')
            ->count('staff.id');

        return [
            ['label' => 'Retirement', 'total' => $retired],
            ['label' => 'Inactive/other exit', 'total' => $inactive],
        ];
    }

    protected function wageBillTrend(float $monthlyWageBill, CarbonImmutable $today): array
    {
        return collect(range(5, 0))
            ->map(function (int $monthsBack) use ($monthlyWageBill, $today): array {
                $date = $today->subMonthsNoOverflow($monthsBack - 1);
                $factor = 1 - ($monthsBack - 1) * 0.012;

                return [
                    'label' => $date->format('M y'),
                    'total' => round(max(0, $monthlyWageBill * $factor), 2),
                ];
            })
            ->values()
            ->all();
    }

    protected function fiveYearWageProjection(float $annualWageBill, int $startYear): array
    {
        return collect(range($startYear, $startYear + 4))
            ->map(fn (int $year, int $index): array => [
                'label' => (string) $year,
                'total' => round($annualWageBill * (1 + (0.072 * ($index + 1))), 2),
            ])
            ->all();
    }

    protected function wageBillByMda(User $user, array $filters): array
    {
        return $this->staffBaseQuery($user, $filters)
            ->join('mdas', 'mdas.id', '=', 'staff.mda_id')
            ->join('staff_salary_placements', function ($join): void {
                $join->on('staff_salary_placements.staff_id', '=', 'staff.id')
                    ->where('staff_salary_placements.is_current', true);
            })
            ->selectRaw('mdas.code as label, SUM(COALESCE(staff_salary_placements.calculated_gross_salary_snapshot, staff_salary_placements.gross_salary, 0)) as total')
            ->groupBy('mdas.code')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'total' => round((float) $row->total, 2)])
            ->all();
    }

    protected function wageInsights(float $annualWageBill, float $projectedAnnualWageBill, User $user, array $filters, int $year): array
    {
        $retiringThisYear = $this->retiringBetween($user, $filters, CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31));
        $averageAnnualCost = $this->staffCount($user, $filters) > 0 ? $annualWageBill / max(1, $this->staffCount($user, $filters)) : 0;

        return [
            ['label' => 'Projected payroll growth', 'value' => '+7.2%', 'tone' => 'positive'],
            ['label' => 'Estimated retirement savings next year', 'value' => $retiringThisYear * $averageAnnualCost, 'tone' => 'warning', 'format' => 'money'],
            ['label' => 'Projected annual pressure', 'value' => max(0, $projectedAnnualWageBill - $annualWageBill), 'tone' => 'info', 'format' => 'money'],
        ];
    }

    protected function staffByCadre(User $user, array $filters): array
    {
        return $this->staffBaseQuery($user, $filters)
            ->leftJoin('cadres', 'cadres.id', '=', 'staff_employments.cadre_id')
            ->selectRaw("COALESCE(cadres.name, 'Unassigned') as label, COUNT(DISTINCT staff.id) as total")
            ->groupBy('cadres.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => ['label' => $row->label, 'total' => (int) $row->total])
            ->all();
    }

    protected function ageBands(User $user, array $filters, CarbonImmutable $today): array
    {
        return collect([
            '20-29' => [$today->subYears(29)->toDateString(), $today->subYears(20)->toDateString()],
            '30-39' => [$today->subYears(39)->toDateString(), $today->subYears(30)->toDateString()],
            '40-49' => [$today->subYears(49)->toDateString(), $today->subYears(40)->toDateString()],
            '50-59' => [$today->subYears(59)->toDateString(), $today->subYears(50)->toDateString()],
            '60+' => [null, $today->subYears(60)->toDateString()],
        ])->map(function (array $range, string $label) use ($user, $filters): array {
            $query = $this->staffBaseQuery($user, $filters);

            if ($range[0] === null) {
                $query->whereDate('staff.date_of_birth', '<=', $range[1]);
            } else {
                $query->whereBetween('staff.date_of_birth', $range);
            }

            return ['label' => $label, 'total' => (int) $query->distinct('staff.id')->count('staff.id')];
        })->values()->all();
    }

    protected function reportingCompliance(User $user, array $filters, int $year): array
    {
        $visibleMdaIds = Mda::query()->visibleToUser($user)->pluck('id')->all();
        $mdaIds = $filters['mda_id'] ? [$filters['mda_id']] : $visibleMdaIds;
        $stationQuery = Station::query()->forMdas($mdaIds);

        if ($filters['station_id']) {
            $stationQuery->whereKey($filters['station_id']);
        }

        $facilitiesTotal = (int) $stationQuery->count();
        $submissionQuery = DB::table('report_submissions')
            ->join('reporting_periods', 'reporting_periods.id', '=', 'report_submissions.reporting_period_id')
            ->whereIn('report_submissions.mda_id', $mdaIds)
            ->where('reporting_periods.period_year', $year)
            ->when($filters['station_id'], fn (Builder $query, int $stationId): Builder => $query->where('report_submissions.station_id', $stationId));

        $facilitiesSubmitted = (clone $submissionQuery)
            ->whereNotNull('report_submissions.station_id')
            ->distinct('report_submissions.station_id')
            ->count('report_submissions.station_id');
        $mdasReported = (clone $submissionQuery)
            ->whereIn('report_submissions.status', ['submitted', 'under_review', 'approved', 'locked'])
            ->distinct('report_submissions.mda_id')
            ->count('report_submissions.mda_id');
        $pendingReturns = (clone $submissionQuery)->where('report_submissions.status', 'returned')->count();
        $lateSubmissions = (clone $submissionQuery)->where('report_submissions.is_late', true)->count();

        return [
            'facilities_total' => $facilitiesTotal,
            'facilities_submitted' => $facilitiesSubmitted,
            'mdas_total' => count($mdaIds),
            'mdas_reported' => $mdasReported,
            'pending_returns' => $pendingReturns,
            'late_submissions' => $lateSubmissions,
            'compliance_percent' => $facilitiesTotal > 0 ? round(($facilitiesSubmitted / $facilitiesTotal) * 100, 1) : 0,
            'trend' => $this->reportingTrend($mdaIds, $filters, $year),
        ];
    }

    protected function reportingTrend(array $mdaIds, array $filters, int $year): array
    {
        return collect(range(1, 12))
            ->map(function (int $month) use ($mdaIds, $filters, $year): array {
                $total = DB::table('report_submissions')
                    ->join('reporting_periods', 'reporting_periods.id', '=', 'report_submissions.reporting_period_id')
                    ->whereIn('report_submissions.mda_id', $mdaIds)
                    ->where('reporting_periods.period_year', $year)
                    ->where('reporting_periods.period_month', $month)
                    ->when($filters['station_id'], fn (Builder $query, int $stationId): Builder => $query->where('report_submissions.station_id', $stationId))
                    ->count();

                return ['label' => CarbonImmutable::create($year, $month, 1)->format('M'), 'total' => $total];
            })
            ->all();
    }

    protected function alerts(User $user, array $filters, int $year, float $annualWageBill, float $projectedAnnualWageBill, array $reporting): array
    {
        $retirementCadres = $this->retirementProjectionByCadre($user, $filters, $year);
        $topRetirementCadre = $retirementCadres[0] ?? null;
        $alerts = [];

        if ($topRetirementCadre && $topRetirementCadre['total'] > 0) {
            $alerts[] = [
                'title' => 'High retirement concentration',
                'message' => $topRetirementCadre['label'].' has '.$topRetirementCadre['total'].' projected retirements in the next five years.',
                'priority' => 'High Priority',
            ];
        }

        if ($annualWageBill > 0 && (($projectedAnnualWageBill - $annualWageBill) / $annualWageBill) >= 0.07) {
            $alerts[] = [
                'title' => 'Payroll pressure projected',
                'message' => 'Projected annual wage bill is trending above the current baseline.',
                'priority' => 'High Priority',
            ];
        }

        if ($reporting['pending_returns'] > 0) {
            $alerts[] = [
                'title' => 'Returned service reports pending',
                'message' => $reporting['pending_returns'].' report submissions require correction.',
                'priority' => 'Medium Priority',
            ];
        }

        if ($alerts === []) {
            $alerts[] = [
                'title' => 'No critical alert',
                'message' => 'Current workforce, payroll, and reporting indicators are within expected limits.',
                'priority' => 'Normal',
            ];
        }

        return $alerts;
    }
}
