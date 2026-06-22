<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\Staff;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class StaffQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, User $user, int $perPage = 15): LengthAwarePaginator
    {
        $query = Staff::query()
            ->with([
                'mda',
                'currentEmployment.department',
                'currentEmployment.station',
                'currentEmployment.cadre',
                'currentEmployment.rank',
                'currentSalaryPlacement.salaryScale',
            ]);

        $this->applyFilters($query, $filters, $user);

        return $query
            ->orderBy('full_name')
            ->paginate(min(max($perPage, 10), 100))
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters, User $user): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('full_name', 'like', "%{$search}%")
                    ->orWhere('staff_number', 'like', "%{$search}%")
                    ->orWhere('legacy_cno', 'like', "%{$search}%")
                    ->orWhere('legacy_psn', 'like', "%{$search}%")
                    ->orWhere('legacy_cno_psn', 'like', "%{$search}%");
            });
        }

        foreach (['legacy_cno' => 'cno', 'legacy_psn' => 'psn'] as $column => $filterKey) {
            $value = trim((string) ($filters[$filterKey] ?? ''));

            if ($value !== '') {
                $query->where($column, 'like', "%{$value}%");
            }
        }

        if (! empty($filters['mda_id'])) {
            $query->where('mda_id', (int) $filters['mda_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['retirement_state'])) {
            $retirementState = (string) $filters['retirement_state'];

            if ($retirementState === 'retired') {
                $query->where(function (Builder $retiredQuery): void {
                    $retiredQuery
                        ->where('status', 'retired')
                        ->orWhereHas('currentEmployment', fn (Builder $employmentQuery) => $employmentQuery->where('employment_status', 'retired'));
                });
            }

            if ($retirementState === 'active') {
                $query->where('status', '!=', 'retired')
                    ->whereHas('currentEmployment', fn (Builder $employmentQuery) => $employmentQuery->where('employment_status', '!=', 'retired'));
            }
        }

        foreach ([
            'department_id' => 'currentEmployment.department_id',
            'station_id' => 'currentEmployment.station_id',
            'cadre_id' => 'currentEmployment.cadre_id',
            'rank_id' => 'currentEmployment.rank_id',
        ] as $filterKey => $column) {
            if (! empty($filters[$filterKey])) {
                $parts = explode('.', $column);
                $relation = $parts[0];
                $field = $parts[1];

                $query->whereHas($relation, fn (Builder $relationQuery) => $relationQuery->where($field, (int) $filters[$filterKey]));
            }
        }

        if (! empty($filters['salary_scale_id'])) {
            $query->whereHas('currentSalaryPlacement', fn (Builder $salaryQuery) => $salaryQuery->where('salary_scale_id', (int) $filters['salary_scale_id']));
        }

        if (! empty($filters['level'])) {
            $query->whereHas('currentSalaryPlacement', fn (Builder $salaryQuery) => $salaryQuery->where('level', (int) $filters['level']));
        }

        return $query;
    }
}
