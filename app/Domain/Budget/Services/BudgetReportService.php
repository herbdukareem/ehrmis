<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Models\BudgetLine;
use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Movement\Models\MovementLine;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BudgetReportService
{
    public const REPORTS = [
        'recurrent-expenditure' => 'Recurrent expenditure',
        'staff-list' => 'Budget staff list',
        'qualification-distribution' => 'Qualification distribution',
        'staff-strength' => 'Staff strength summary',
    ];

    public function build(BudgetWorkbook $workbook, string $report): array
    {
        if (! array_key_exists($report, self::REPORTS)) {
            throw new InvalidArgumentException('Unsupported budget report.');
        }

        $workbook->loadMissing(['mda', 'movementWorkbook']);

        return match ($report) {
            'recurrent-expenditure' => $this->recurrentExpenditure($workbook),
            'staff-list' => $this->staffList($workbook),
            'qualification-distribution' => $this->qualificationDistribution($workbook),
            'staff-strength' => $this->staffStrength($workbook),
        };
    }

    protected function recurrentExpenditure(BudgetWorkbook $workbook): array
    {
        $lines = $workbook->lines()
            ->with(['department', 'salaryScale'])
            ->orderBy('department_id')
            ->orderBy('salary_scale_id')
            ->orderBy('level')
            ->get();

        $previousLines = BudgetLine::query()
            ->whereHas('workbook', fn ($query) => $query
                ->where('mda_id', $workbook->mda_id)
                ->where('year', $workbook->year - 1)
                ->whereIn('status', ['approved', 'locked']))
            ->get()
            ->keyBy(fn (BudgetLine $line): string => $this->lineKey($line->department_id, $line->salary_scale_id, $line->level));

        $groups = $lines
            ->groupBy(fn (BudgetLine $line): string => $this->departmentLabel($line).'|'.$this->scaleLabel($line))
            ->map(function (Collection $group) use ($previousLines): array {
                $first = $group->first();
                $rows = $group->map(function (BudgetLine $line) use ($previousLines): array {
                    $previous = $previousLines->get($this->lineKey($line->department_id, $line->salary_scale_id, $line->level));

                    return [
                        'level' => $line->level,
                        'approved_staff' => (int) ($previous?->staff_count ?? 0),
                        'actual_staff' => (int) $line->staff_count,
                        'approved_estimate' => $this->annualize($previous?->proposed_gross_total ?? 0),
                        'actual_expense' => $this->semiAnnualize($line->current_gross_total),
                        'required_staff' => max(0, (int) $line->staff_count - (int) $line->retiring_count),
                        'proposed_estimate' => $this->annualize($line->proposed_gross_total),
                    ];
                })->values();

                return [
                    'department' => $this->departmentLabel($first),
                    'scale' => $this->scaleLabel($first),
                    'rows' => $rows,
                    'totals' => [
                        'approved_staff' => $rows->sum('approved_staff'),
                        'actual_staff' => $rows->sum('actual_staff'),
                        'approved_estimate' => $rows->sum('approved_estimate'),
                        'actual_expense' => $rows->sum('actual_expense'),
                        'required_staff' => $rows->sum('required_staff'),
                        'proposed_estimate' => $rows->sum('proposed_estimate'),
                    ],
                ];
            })
            ->values();

        return [
            'type' => 'recurrent-expenditure',
            'title' => $this->budgetYear($workbook).' Proposed Recurrent Expenditure',
            'groups' => $groups,
            'grand_totals' => [
                'approved_staff' => $groups->sum(fn (array $group): int|float => $group['totals']['approved_staff']),
                'actual_staff' => $groups->sum(fn (array $group): int|float => $group['totals']['actual_staff']),
                'approved_estimate' => $groups->sum(fn (array $group): int|float => $group['totals']['approved_estimate']),
                'actual_expense' => $groups->sum(fn (array $group): int|float => $group['totals']['actual_expense']),
                'required_staff' => $groups->sum(fn (array $group): int|float => $group['totals']['required_staff']),
                'proposed_estimate' => $groups->sum(fn (array $group): int|float => $group['totals']['proposed_estimate']),
            ],
        ];
    }

    protected function staffList(BudgetWorkbook $workbook): array
    {
        $lines = $this->movementLines($workbook)
            ->sortBy([
                fn (MovementLine $line): string => $line->currentEmployment?->department?->name ?? '',
                fn (MovementLine $line): int => -1 * (int) ($line->current_level ?? 0),
                fn (MovementLine $line): string => $line->staff?->full_name ?? '',
            ])
            ->values();

        return [
            'type' => 'staff-list',
            'title' => $this->budgetYear($workbook).' Budget Staff List',
            'groups' => $lines
                ->groupBy(fn (MovementLine $line): string => $line->currentEmployment?->department?->name ?? 'Unassigned')
                ->map(fn (Collection $group, string $department): array => [
                    'department' => $department,
                    'rows' => $group->values()->map(fn (MovementLine $line, int $index): array => $this->staffRow($line, $index + 1)),
                ])
                ->values(),
        ];
    }

    protected function qualificationDistribution(BudgetWorkbook $workbook): array
    {
        $lines = $this->movementLines($workbook)
            ->reject(fn (MovementLine $line): bool => in_array($line->retirement_status, ['retiring', 'retired'], true))
            ->values();

        $qualifications = $lines
            ->map(fn (MovementLine $line): string => $this->qualification($line))
            ->unique()
            ->sort()
            ->values();

        $groups = $lines
            ->groupBy(fn (MovementLine $line): string => ($line->currentEmployment?->department?->name ?? 'Unassigned').'|'.($line->proposedSalaryScale?->code ?? $line->currentSalaryScale?->code ?? 'N/A'))
            ->map(function (Collection $group) use ($qualifications): array {
                $first = $group->first();
                $levels = $group
                    ->map(fn (MovementLine $line): int => (int) ($line->proposed_level ?? $line->current_level ?? 0))
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values();

                return [
                    'department' => $first->currentEmployment?->department?->name ?? 'Unassigned',
                    'scale' => $first->proposedSalaryScale?->code ?? $first->currentSalaryScale?->code ?? 'N/A',
                    'qualifications' => $qualifications,
                    'rows' => $levels->map(function (int $level) use ($group, $qualifications): array {
                        $levelLines = $group->filter(fn (MovementLine $line): bool => (int) ($line->proposed_level ?? $line->current_level ?? 0) === $level);

                        $cells = $qualifications->map(function (string $qualification) use ($levelLines): array {
                            $qualified = $levelLines->filter(fn (MovementLine $line): bool => $this->qualification($line) === $qualification);

                            return [
                                'male' => $qualified->filter(fn (MovementLine $line): bool => $this->sex($line) === 'M')->count(),
                                'female' => $qualified->filter(fn (MovementLine $line): bool => $this->sex($line) === 'F')->count(),
                            ];
                        });

                        return [
                            'level' => $level,
                            'cells' => $cells,
                            'total' => $cells->sum(fn (array $cell): int => $cell['male'] + $cell['female']),
                        ];
                    }),
                ];
            })
            ->values();

        return [
            'type' => 'qualification-distribution',
            'title' => $this->budgetYear($workbook).' Qualification Distribution',
            'groups' => $groups,
        ];
    }

    protected function staffStrength(BudgetWorkbook $workbook): array
    {
        $groups = $workbook->lines()
            ->with(['department', 'salaryScale'])
            ->get()
            ->groupBy(fn (BudgetLine $line): string => $this->departmentLabel($line))
            ->map(function (Collection $lines, string $department): array {
                return [
                    'department' => $department,
                    'staff_count' => $lines->sum('staff_count'),
                    'retiring_count' => $lines->sum('retiring_count'),
                    'required_staff' => $lines->sum(fn (BudgetLine $line): int => max(0, (int) $line->staff_count - (int) $line->retiring_count)),
                    'current_gross_total' => $lines->sum('current_gross_total'),
                    'proposed_gross_total' => $lines->sum('proposed_gross_total'),
                ];
            })
            ->values();

        return [
            'type' => 'staff-strength',
            'title' => $this->budgetYear($workbook).' Staff Strength Summary',
            'groups' => $groups,
            'totals' => [
                'staff_count' => $groups->sum('staff_count'),
                'retiring_count' => $groups->sum('retiring_count'),
                'required_staff' => $groups->sum('required_staff'),
                'current_gross_total' => $groups->sum('current_gross_total'),
                'proposed_gross_total' => $groups->sum('proposed_gross_total'),
            ],
        ];
    }

    protected function movementLines(BudgetWorkbook $workbook): Collection
    {
        return $workbook->movementWorkbook
            ?->lines()
            ->with([
                'staff.personalDetail',
                'staff.qualifications.qualificationType',
                'currentEmployment.department',
                'currentEmployment.rank',
                'currentSalaryScale',
                'proposedSalaryScale',
            ])
            ->get() ?? collect();
    }

    protected function staffRow(MovementLine $line, int $serialNumber): array
    {
        $staff = $line->staff;
        $employment = $line->currentEmployment;

        return [
            'sn' => $serialNumber,
            'name' => $staff?->full_name,
            'sex' => $this->sex($line),
            'dob' => $staff?->date_of_birth?->format('Y-m-d'),
            'lga' => $staff?->personalDetail?->lga,
            'qualification' => $this->qualification($line),
            'dfa' => $employment?->date_first_appointment?->format('Y-m-d'),
            'dpa' => $employment?->date_last_promotion?->format('Y-m-d'),
            'rank' => $employment?->rank?->name,
            'level_step' => trim(($line->currentSalaryScale?->code ?? '').' '.($line->current_level ?? '').'/'.($line->current_step ?? '')),
            'psn' => $staff?->legacy_psn,
            'file_no' => $staff?->personalDetail?->file_no,
            'cno' => $staff?->legacy_cno,
            'remark' => str($line->eligibility_status)->replace('_', ' ')->title().' / '.str($line->retirement_status)->replace('_', ' ')->title(),
        ];
    }

    protected function qualification(MovementLine $line): string
    {
        $qualification = $line->staff?->qualifications
            ->firstWhere('is_highest', true)
            ?? $line->staff?->qualifications?->first();

        return $qualification?->highest_qualification_name
            ?? $qualification?->qualification_name
            ?? $qualification?->qualificationType?->name
            ?? 'Unspecified';
    }

    protected function sex(MovementLine $line): string
    {
        $sex = strtoupper((string) $line->staff?->sex);

        return str_starts_with($sex, 'F') ? 'F' : 'M';
    }

    protected function budgetYear(BudgetWorkbook $workbook): int
    {
        return (int) ($workbook->movementWorkbook?->budget_year ?? $workbook->year + 1);
    }

    protected function departmentLabel(BudgetLine $line): string
    {
        return $line->department?->name ?? 'Unassigned';
    }

    protected function scaleLabel(BudgetLine $line): string
    {
        return trim(($line->salaryScale?->code ?? 'N/A').' - '.($line->salaryScale?->name ?? ''));
    }

    protected function lineKey(?int $departmentId, ?int $salaryScaleId, ?int $level): string
    {
        return implode('|', [$departmentId ?? 0, $salaryScaleId ?? 0, $level ?? 0]);
    }

    protected function annualize(mixed $amount): float
    {
        return round((float) $amount * 12, 2);
    }

    protected function semiAnnualize(mixed $amount): float
    {
        return round((float) $amount * 6, 2);
    }
}
