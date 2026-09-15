<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Models\BudgetLine;
use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Movement\Models\MovementLine;
use App\Support\ReportFormatter;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BudgetReportService
{
    public function __construct(protected BudgetAllowanceSummaryService $allowanceSummaryService) {}

    public const EXCEL_REPORTS = ['recurrent-expenditure', 'staff-list', 'qualification-distribution', 'manpower-distribution'];

    public const REPORTS = [
        'recurrent-expenditure' => 'Recurrent expenditure',
        'staff-list' => 'Budget staff list',
        'qualification-distribution' => 'Qualification distribution',
        'manpower-distribution' => 'Manpower distribution',
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
            'manpower-distribution' => $this->manpowerDistribution($workbook),
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

        $previousWorkbook = BudgetWorkbook::query()
            ->where('mda_id', $workbook->mda_id)
            ->where('year', $workbook->year - 1)
            ->whereIn('status', ['approved', 'locked'])
            ->orderByDesc('approved_at')->orderByDesc('id')->first();
        $previousBudgetLines = $previousWorkbook?->lines()->get() ?? collect();
        $previousLines = $previousBudgetLines
            ->keyBy(fn (BudgetLine $line): string => $this->lineKey($line->department_id, $line->salary_scale_id, $line->level));
        $allowances = $this->allowanceSummaryService->forWorkbook($workbook, $lines);
        $previousAllowances = $previousWorkbook
            ? $this->allowanceSummaryService->forWorkbook($previousWorkbook, $previousBudgetLines)
            : collect();
        $centralized = $allowances !== null && $previousAllowances !== null;
        $zeroTotals = ['approved_staff' => 0, 'actual_staff' => 0, 'approved_estimate' => 0, 'actual_expense' => 0, 'required_staff' => 0, 'proposed_estimate' => 0];
        $movementRows = $this->movementBudgetRows($workbook);
        $notes = $centralized || $lines->isEmpty() ? [] : [
            'Allowances remain included in the grade-level figures because the saved salary breakdown is unavailable or does not match this budget. No separate allowance amount has been added.',
        ];

        $groups = $lines
            ->groupBy(fn (BudgetLine $line): string => ($line->department_id ?? 0).'|'.($line->salary_scale_id ?? 0))
            ->map(function (Collection $group) use ($previousLines, $allowances, $previousAllowances, $centralized, $movementRows, $zeroTotals): array {
                $first = $group->first();
                $groupAllowanceTotals = $zeroTotals;
                $rows = $group->map(function (BudgetLine $line) use ($previousLines, $allowances, $previousAllowances, $centralized, $movementRows, &$groupAllowanceTotals): array {
                    $previous = $previousLines->get($this->lineKey($line->department_id, $line->salary_scale_id, $line->level));
                    $currentAllowance = $centralized ? ($allowances->get($line->id)['current'] ?? 0) : 0;
                    $proposedAllowance = $centralized ? ($allowances->get($line->id)['proposed'] ?? 0) : 0;
                    $approvedAllowance = $centralized && $previous ? ($previousAllowances->get($previous->id)['proposed'] ?? 0) : 0;
                    $movementRow = $movementRows->get($this->lineKey($line->department_id, $line->salary_scale_id, $line->level));
                    $fallbackRequiredStaff = max(0, (int) $line->staff_count - (int) $line->retiring_count);
                    $requiredStaff = $line->required_staff_count
                        ?? ($movementRow['required_staff_count'] ?? null)
                        ?? $fallbackRequiredStaff;
                    $actualStaff = $line->required_staff_count === null && $movementRow !== null
                        ? (int) $movementRow['actual_staff']
                        : (int) $line->staff_count;
                    $currentGrossTotal = $line->required_staff_count === null && $movementRow !== null
                        ? (float) $movementRow['current_gross_total']
                        : (float) $line->current_gross_total;
                    $proposedGrossTotal = $line->required_staff_count === null
                        && $movementRow !== null
                        && (int) $movementRow['required_staff_count'] !== $fallbackRequiredStaff
                            ? (float) $movementRow['proposed_gross_total']
                            : (float) $line->proposed_gross_total;
                    $groupAllowanceTotals['approved_estimate'] += $this->annualize($approvedAllowance);
                    $groupAllowanceTotals['actual_expense'] += $this->semiAnnualize($currentAllowance);
                    $groupAllowanceTotals['proposed_estimate'] += $this->annualize($proposedAllowance);

                    return [
                        'level' => $line->level,
                        'approved_staff' => (int) ($previous?->staff_count ?? 0),
                        'actual_staff' => $actualStaff,
                        'approved_estimate' => $this->annualize(($previous?->proposed_gross_total ?? 0) - $approvedAllowance),
                        'actual_expense' => $this->semiAnnualize($currentGrossTotal - $currentAllowance),
                        'required_staff' => $requiredStaff,
                        'proposed_estimate' => $this->annualize($proposedGrossTotal - $proposedAllowance),
                    ];
                })->values();

                return [
                    'department_id' => $first->department_id,
                    'salary_scale_id' => $first->salary_scale_id,
                    'is_administration' => in_array(strtolower(trim($first->department?->code ?? '')), ['admin', 'administration'], true)
                        || in_array(strtolower(trim($this->departmentLabel($first))), ['admin', 'administration'], true),
                    'department' => $this->departmentLabel($first),
                    'scale' => $this->scaleLabel($first),
                    'scale_code' => $first->salaryScale?->code ?? 'N/A',
                    'grade_label' => $this->gradeLabel($first->salaryScale?->code, $first->salaryScale?->name),
                    'min_level' => $first->salaryScale?->min_level,
                    'max_level' => $first->salaryScale?->max_level,
                    'rows' => $rows,
                    'totals' => [
                        'approved_staff' => $rows->sum('approved_staff'),
                        'actual_staff' => $rows->sum('actual_staff'),
                        'approved_estimate' => $rows->sum('approved_estimate'),
                        'actual_expense' => $rows->sum('actual_expense'),
                        'required_staff' => $rows->sum('required_staff'),
                        'proposed_estimate' => $rows->sum('proposed_estimate'),
                    ],
                    'allowance_totals' => $groupAllowanceTotals,
                ];
            })
            ->values();

        $existingKeys = $lines->map(fn (BudgetLine $line): string => $this->lineKey($line->department_id, $line->salary_scale_id, $line->level));
        $movementOnlyRows = $movementRows
            ->reject(fn (array $row, string $key): bool => $existingKeys->contains($key))
            ->values();

        if ($movementOnlyRows->isNotEmpty()) {
            $groups = $this->appendMovementOnlyRequiredRows($groups, $movementOnlyRows);
        }

        $groups = $groups->map(fn (array $group): array => $this->expandRecurrentGroupLevels($group));

        if ($centralized && $groups->isNotEmpty()) {
            $groups = $groups->map(function (array $group): array {
                $baseTotals = $group['totals'];
                $groupAllowanceTotals = $group['allowance_totals'] ?? array_fill_keys(array_keys($baseTotals), 0);

                foreach ($groupAllowanceTotals as $key => $amount) {
                    $group['totals'][$key] = round($group['totals'][$key] + $amount, 2);
                }
                $group['summary_rows'] = $this->legacyRecurrentSummaryRows($group, $baseTotals, $groupAllowanceTotals, true);

                return $group;
            });
        } else {
            $groups = $groups->map(function (array $group) use ($zeroTotals): array {
                $group['summary_rows'] = $this->legacyRecurrentSummaryRows($group, $group['totals'], $zeroTotals, false);

                return $group;
            });
        }

        return [
            'type' => 'recurrent-expenditure',
            'title' => $this->budgetYear($workbook).' Proposed Recurrent Expenditure',
            'groups' => $groups,
            'notes' => $notes,
            'allowances_centralized' => $centralized,
            'grand_totals' => [
                'approved_staff' => (int) $groups->sum(fn (array $group): int|float => $group['totals']['approved_staff']),
                'actual_staff' => (int) $groups->sum(fn (array $group): int|float => $group['totals']['actual_staff']),
                'approved_estimate' => $groups->sum(fn (array $group): int|float => $group['totals']['approved_estimate']),
                'actual_expense' => $groups->sum(fn (array $group): int|float => $group['totals']['actual_expense']),
                'required_staff' => (int) $groups->sum(fn (array $group): int|float => $group['totals']['required_staff']),
                'proposed_estimate' => $groups->sum(fn (array $group): int|float => $group['totals']['proposed_estimate']),
            ],
        ];
    }

    protected function staffList(BudgetWorkbook $workbook): array
    {
        $lines = $this->movementLines($workbook)
            ->filter(fn (MovementLine $line): bool => $line->countsAsCurrentStaff())
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
                ->groupBy(fn (MovementLine $line) => $line->currentEmployment?->department_id ?? 'unassigned')
                ->map(function (Collection $group): array {
                    $rows = $group->values()->map(fn (MovementLine $line): array => $this->staffRow($line));
                    $sections = $this->numberStaffListSections($this->staffListSections($rows));

                    return [
                        'department_id' => $group->first()->currentEmployment?->department_id,
                        'department' => $group->first()->currentEmployment?->department?->name ?? 'Unassigned',
                        'sections' => $sections,
                        'rows' => $sections->flatMap(fn (array $section): Collection => $section['rows'])->values(),
                    ];
                })
                ->values(),
        ];
    }

    protected function qualificationDistribution(BudgetWorkbook $workbook): array
    {
        $lines = $this->movementLines($workbook)
            ->filter(fn (MovementLine $line): bool => $line->countsAsRequiredStaff())
            ->values();

        $qualifications = $lines
            ->map(fn (MovementLine $line): string => $this->qualification($line))
            ->unique()
            ->sort()
            ->values();

        $groups = $lines
            ->groupBy(fn (MovementLine $line): string => ($line->currentEmployment?->department_id ?? 'unassigned').'|'.($line->proposed_salary_scale_id ?? $line->current_salary_scale_id ?? 'unassigned'))
            ->map(function (Collection $group) use ($qualifications): array {
                $first = $group->first();
                $levels = $group
                    ->map(fn (MovementLine $line): int => (int) ($line->proposed_level ?? $line->current_level ?? 0))
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values();

                return [
                    'department_id' => $first->currentEmployment?->department_id,
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

    protected function manpowerDistribution(BudgetWorkbook $workbook): array
    {
        $lines = $this->movementLines($workbook)
            ->filter(fn (MovementLine $line): bool => $line->countsAsRequiredStaff())
            ->values();

        $groups = $lines
            ->groupBy(fn (MovementLine $line) => $line->currentEmployment?->department_id ?? 'unassigned')
            ->map(function (Collection $departmentLines): array {
                $first = $departmentLines->first();
                $sections = $departmentLines
                    ->groupBy(fn (MovementLine $line): string => (string) ($line->proposed_salary_scale_id ?? $line->current_salary_scale_id ?? 'unassigned'))
                    ->map(function (Collection $scaleLines): array {
                        $first = $scaleLines->first();
                        $salaryScale = $first->proposedSalaryScale ?? $first->currentSalaryScale;
                        $scaleCode = $salaryScale?->code ?? 'N/A';
                        $rowsByLevel = $scaleLines->groupBy(fn (MovementLine $line): int => (int) ($line->proposed_level ?? $line->current_level ?? 0));
                        $observedLevels = $rowsByLevel->keys()->filter()->map(fn ($level): int => (int) $level);
                        $minLevel = (int) ($salaryScale?->min_level ?? $observedLevels->min() ?? 1);
                        $maxLevel = (int) ($salaryScale?->max_level ?? $observedLevels->max() ?? $minLevel);
                        $levels = $minLevel > 0 && $maxLevel >= $minLevel
                            ? collect(range($minLevel, $maxLevel))
                            : $observedLevels->sort()->values();

                        $rows = $levels->map(function (int $level) use ($rowsByLevel, $scaleCode): array {
                            $levelLines = $rowsByLevel->get($level, collect());
                            $male = $levelLines->filter(fn (MovementLine $line): bool => $this->sex($line) === 'M')->count();
                            $female = $levelLines->filter(fn (MovementLine $line): bool => $this->sex($line) === 'F')->count();

                            return [
                                'level' => $level,
                                'label' => $scaleCode.$level,
                                'male' => $male,
                                'female' => $female,
                                'total' => $male + $female,
                            ];
                        })->values();

                        return [
                            'scale' => trim($scaleCode.' - '.($salaryScale?->name ?? '')),
                            'scale_code' => $scaleCode,
                            'rows' => $rows,
                            'totals' => [
                                'male' => $rows->sum('male'),
                                'female' => $rows->sum('female'),
                                'total' => $rows->sum('total'),
                            ],
                        ];
                    })
                    ->sortBy('scale')
                    ->values();

                $occupationRows = $this->manpowerOccupationRows($departmentLines);

                return [
                    'department_id' => $first->currentEmployment?->department_id,
                    'department' => $first->currentEmployment?->department?->name ?? 'Unassigned',
                    'sections' => $sections,
                    'occupation_rows' => $occupationRows,
                    'occupation_totals' => [
                        'male' => $occupationRows->sum('male'),
                        'female' => $occupationRows->sum('female'),
                        'total' => $occupationRows->sum('total'),
                    ],
                ];
            })
            ->values();

        return [
            'type' => 'manpower-distribution',
            'title' => $this->budgetYear($workbook).' Manpower Distribution',
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
                    'required_staff' => $lines->sum(fn (BudgetLine $line): int => $line->required_staff_count ?? max(0, (int) $line->staff_count - (int) $line->retiring_count)),
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
                'currentEmployment.cadre',
                'currentEmployment.rank',
                'currentSalaryScale',
                'proposedSalaryScale',
            ])
            ->get() ?? collect();
    }

    protected function movementBudgetRows(BudgetWorkbook $workbook): Collection
    {
        $lines = $workbook->movementWorkbook
            ?->lines()
            ->with(['staff', 'currentEmployment.department', 'currentSalaryScale', 'proposedSalaryScale'])
            ->get() ?? collect();

        $rows = [];

        foreach ($lines as $line) {
            $departmentId = $line->currentEmployment?->department_id;
            $currentKey = $this->lineKey($departmentId, $line->current_salary_scale_id, $line->current_level);
            $this->initializeMovementRequiredRow(
                $rows,
                $currentKey,
                $departmentId,
                $line->currentEmployment?->department?->name ?? 'Unassigned',
                $line->current_salary_scale_id,
                $line->currentSalaryScale,
                $line->current_level,
            );

            if ($line->countsAsCurrentStaff()) {
                $rows[$currentKey]['actual_staff']++;
                $rows[$currentKey]['retiring_count'] += $line->retirement_status === 'retiring' && ! $line->hasMovementOverride() ? 1 : 0;
                $rows[$currentKey]['current_gross_total'] += (float) ($line->current_amounts['calculated_gross'] ?? 0);
            }

            if (! $line->countsAsRequiredStaff()) {
                continue;
            }

            $salaryScale = $line->proposedSalaryScale ?? $line->currentSalaryScale;
            $salaryScaleId = $line->proposed_salary_scale_id ?? $line->current_salary_scale_id;
            $level = $line->proposed_level ?? $line->current_level;
            $proposedKey = $this->lineKey($departmentId, $salaryScaleId, $level);

            $this->initializeMovementRequiredRow(
                $rows,
                $proposedKey,
                $departmentId,
                $line->currentEmployment?->department?->name ?? 'Unassigned',
                $salaryScaleId,
                $salaryScale,
                $level,
            );

            $rows[$proposedKey]['required_staff_count']++;
            $rows[$proposedKey]['proposed_gross_total'] += (float) ($line->proposed_amounts['calculated_gross'] ?? 0);
        }

        return collect($rows)->map(function (array $row): array {
            $row['current_gross_total'] = round($row['current_gross_total'], 2);
            $row['proposed_gross_total'] = round($row['proposed_gross_total'], 2);

            return $row;
        });
    }

    protected function initializeMovementRequiredRow(
        array &$rows,
        string $key,
        ?int $departmentId,
        string $department,
        ?int $salaryScaleId,
        mixed $salaryScale,
        ?int $level,
    ): void {
        $rows[$key] ??= [
            'department_id' => $departmentId,
            'department' => $department,
            'salary_scale_id' => $salaryScaleId,
            'scale' => trim(($salaryScale?->code ?? 'N/A').' - '.($salaryScale?->name ?? '')),
            'scale_code' => $salaryScale?->code ?? 'N/A',
            'min_level' => $salaryScale?->min_level,
            'max_level' => $salaryScale?->max_level,
            'level' => $level,
            'actual_staff' => 0,
            'retiring_count' => 0,
            'required_staff_count' => 0,
            'current_gross_total' => 0.0,
            'proposed_gross_total' => 0.0,
        ];
    }

    protected function appendMovementOnlyRequiredRows(Collection $groups, Collection $movementRows): Collection
    {
        $groupedMovementRows = $movementRows->groupBy(fn (array $row): string => ($row['department_id'] ?? 0).'|'.($row['salary_scale_id'] ?? 0));

        $groups = $groups->map(function (array $group) use (&$groupedMovementRows): array {
            $key = ($group['department_id'] ?? 0).'|'.($group['salary_scale_id'] ?? 0);
            $rows = $groupedMovementRows->pull($key, collect());

            if ($rows->isEmpty()) {
                return $group;
            }

            $group['rows'] = $this->mergeMovementOnlyRows($group['rows'], $rows);
            $group['totals'] = $this->recurrentTotals($group['rows']);

            return $group;
        });

        foreach ($groupedMovementRows as $rows) {
            $first = $rows->first();
            $reportRows = $this->mergeMovementOnlyRows(collect(), $rows);
            $groups->push([
                'department_id' => $first['department_id'],
                'salary_scale_id' => $first['salary_scale_id'],
                'is_administration' => in_array(strtolower(trim($first['department'])), ['admin', 'administration'], true),
                'department' => $first['department'],
                'scale' => $first['scale'],
                'scale_code' => $first['scale_code'],
                'grade_label' => $this->gradeLabel($first['scale_code'], $this->scaleNameFromLabel($first['scale'])),
                'min_level' => $first['min_level'],
                'max_level' => $first['max_level'],
                'rows' => $reportRows,
                'totals' => $this->recurrentTotals($reportRows),
                'allowance_totals' => array_fill_keys(array_keys($this->recurrentTotals($reportRows)), 0),
            ]);
        }

        return $groups->sortBy([
            ['department', 'asc'],
            ['scale', 'asc'],
        ])->values();
    }

    protected function mergeMovementOnlyRows(Collection $reportRows, Collection $movementRows): Collection
    {
        return $reportRows
            ->concat($movementRows->map(fn (array $row): array => [
                'level' => $row['level'],
                'approved_staff' => 0,
                'actual_staff' => $row['actual_staff'],
                'approved_estimate' => 0,
                'actual_expense' => $this->semiAnnualize($row['current_gross_total']),
                'required_staff' => $row['required_staff_count'],
                'proposed_estimate' => $this->annualize($row['proposed_gross_total']),
            ]))
            ->sortBy('level')
            ->values();
    }

    protected function expandRecurrentGroupLevels(array $group): array
    {
        if (($group['scale_code'] ?? '') === 'Allowances') {
            return $group;
        }

        $rowsByLevel = $group['rows']->keyBy('level');
        $levels = $rowsByLevel->keys()->filter(fn ($level): bool => $level !== null)->map(fn ($level): int => (int) $level);
        $minLevel = (int) ($group['min_level'] ?? $levels->min() ?? 1);
        $maxLevel = (int) ($group['max_level'] ?? $levels->max() ?? $minLevel);

        if ($minLevel < 1 || $maxLevel < $minLevel) {
            return $group;
        }

        $group['rows'] = collect(range($minLevel, $maxLevel))
            ->map(fn (int $level): array => $rowsByLevel->get($level) ?? $this->emptyRecurrentRow($level))
            ->values();
        $group['totals'] = $this->recurrentTotals($group['rows']);

        return $group;
    }

    protected function legacyRecurrentSummaryRows(array $group, array $staffTotals, array $allowanceTotals, bool $showAllowanceAmounts): array
    {
        $grantTotals = array_fill_keys(array_keys($staffTotals), 0);
        $personnelTotals = [];

        foreach ($staffTotals as $key => $amount) {
            $personnelTotals[$key] = round((float) $amount + (float) ($allowanceTotals[$key] ?? 0) + (float) ($grantTotals[$key] ?? 0), 2);
        }

        return [
            ['label' => $this->levelTotalLabel($group), ...$this->countOnlyTotals($staffTotals)],
            ['label' => 'S/GRADE', ...$this->blankRecurrentTotals()],
            ['label' => 'TOTAL FOR ALL STAFF', ...$this->amountOnlyTotals($staffTotals)],
            ['label' => 'TOTAL ALLOWANCE FOR ALL STAFF', ...($showAllowanceAmounts ? $this->amountOnlyTotals($allowanceTotals) : $this->blankRecurrentTotals())],
            ['label' => 'L/GRANT', ...$this->blankRecurrentTotals()],
            ['label' => 'TOTAL PERSONNEL COST', ...$personnelTotals],
        ];
    }

    protected function levelTotalLabel(array $group): string
    {
        $levels = collect($group['rows'] ?? [])
            ->pluck('level')
            ->filter(fn ($level): bool => $level !== null)
            ->map(fn ($level): int => (int) $level);

        if ($levels->isEmpty()) {
            return 'TOTAL';
        }

        return 'TOTAL '.$levels->min().' - '.$levels->max();
    }

    protected function blankRecurrentTotals(): array
    {
        return [
            'approved_staff' => null,
            'actual_staff' => null,
            'approved_estimate' => null,
            'actual_expense' => null,
            'required_staff' => null,
            'proposed_estimate' => null,
        ];
    }

    protected function countOnlyTotals(array $totals): array
    {
        return [
            'approved_staff' => $totals['approved_staff'] ?? 0,
            'actual_staff' => $totals['actual_staff'] ?? 0,
            'approved_estimate' => 0,
            'actual_expense' => 0,
            'required_staff' => $totals['required_staff'] ?? 0,
            'proposed_estimate' => 0,
        ];
    }

    protected function amountOnlyTotals(array $totals): array
    {
        return [
            'approved_staff' => null,
            'actual_staff' => null,
            'approved_estimate' => $totals['approved_estimate'] ?? 0,
            'actual_expense' => $totals['actual_expense'] ?? 0,
            'required_staff' => null,
            'proposed_estimate' => $totals['proposed_estimate'] ?? 0,
        ];
    }

    protected function emptyRecurrentRow(int $level): array
    {
        return [
            'level' => $level,
            'approved_staff' => 0,
            'actual_staff' => 0,
            'approved_estimate' => 0,
            'actual_expense' => 0,
            'required_staff' => 0,
            'proposed_estimate' => 0,
        ];
    }

    protected function recurrentTotals(Collection $rows): array
    {
        return [
            'approved_staff' => $rows->sum('approved_staff'),
            'actual_staff' => $rows->sum('actual_staff'),
            'approved_estimate' => $rows->sum('approved_estimate'),
            'actual_expense' => $rows->sum('actual_expense'),
            'required_staff' => $rows->sum('required_staff'),
            'proposed_estimate' => $rows->sum('proposed_estimate'),
        ];
    }

    protected function staffRow(MovementLine $line): array
    {
        $staff = $line->staff;
        $employment = $line->currentEmployment;
        $qualification = $staff?->qualifications->firstWhere('is_highest', true)
            ?? $staff?->qualifications->first();

        return [
            'sn' => null,
            'name' => ReportFormatter::personName($staff?->full_name),
            'sex' => $this->sex($line),
            'dob' => $staff?->date_of_birth?->format('Y-m-d'),
            'lga' => $staff?->personalDetail?->lga,
            'qualification' => filled($qualification?->qualification_name) ? $qualification->qualification_name : 'Unspecified',
            'dfa' => $employment?->date_first_appointment?->format('Y-m-d'),
            'dpa' => $employment?->date_last_promotion?->format('Y-m-d'),
            'rank' => $employment?->rank?->name,
            'level_step' => trim(($line->currentSalaryScale?->code ?? '').' '.($line->current_level ?? '').'/'.($line->current_step ?? '')),
            'scale_code' => $line->currentSalaryScale?->code ?? 'N/A',
            'level' => $line->current_level,
            'psn' => $staff?->legacy_psn,
            'file_no' => $staff?->personalDetail?->file_no,
            'cno' => ReportFormatter::cno($staff?->legacy_cno, $staff?->staff_number),
            'remark' => str($line->isContractStaffForMovement() ? 'contract' : $line->eligibility_status)->replace('_', ' ')->title().' / '.str($line->retirement_status)->replace('_', ' ')->title(),
        ];
    }

    protected function staffListSections(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (array $row): string => ($row['scale_code'] ?? 'N/A').'|'.($row['level'] ?? 0))
            ->map(function (Collection $sectionRows): array {
                $first = $sectionRows->first();

                return [
                    'scale_code' => $first['scale_code'] ?? 'N/A',
                    'level' => $first['level'],
                    'title' => trim(($first['scale_code'] ?? 'N/A').' '.($first['level'] ?? '-')).' ('.$sectionRows->count().')',
                    'rows' => $sectionRows->sortBy('name')->values(),
                ];
            })
            ->sortBy([
                ['scale_code', 'asc'],
                ['level', 'desc'],
            ])
            ->values();
    }

    protected function numberStaffListSections(Collection $sections): Collection
    {
        $serialNumber = 1;

        return $sections
            ->map(function (array $section) use (&$serialNumber): array {
                $section['rows'] = $section['rows']
                    ->map(function (array $row) use (&$serialNumber): array {
                        $row['sn'] = $serialNumber++;

                        return $row;
                    })
                    ->values();

                return $section;
            })
            ->values();
    }

    protected function manpowerOccupationRows(Collection $lines): Collection
    {
        $labels = [
            'professional' => 'Professional/Technicians',
            'administrative' => 'Administrative/Managerial',
            'clerical' => 'Clerical',
            'others' => 'Others',
        ];

        return collect($labels)
            ->map(function (string $label, string $key) use ($lines): array {
                $matched = $lines->filter(fn (MovementLine $line): bool => $this->manpowerOccupationKey($line) === $key);
                $male = $matched->filter(fn (MovementLine $line): bool => $this->sex($line) === 'M')->count();
                $female = $matched->filter(fn (MovementLine $line): bool => $this->sex($line) === 'F')->count();

                return [
                    'occupation' => $label,
                    'male' => $male,
                    'female' => $female,
                    'total' => $male + $female,
                ];
            })
            ->values();
    }

    protected function manpowerOccupationKey(MovementLine $line): string
    {
        $source = collect([
            $line->currentEmployment?->staff_category,
            $line->currentEmployment?->cadre?->name,
            $line->currentEmployment?->rank?->name,
        ])->filter()->implode(' ');

        $normalized = str($source)->lower()->toString();

        if (preg_match('/\b(clerical|clerk|typist|secretarial|registry|records?)\b/', $normalized)) {
            return 'clerical';
        }

        if (preg_match('/\b(admin|administrative|manager|managerial|director|executive|account|finance|human resource|hr)\b/', $normalized)) {
            return 'administrative';
        }

        if (preg_match('/\b(professional|technician|technical|doctor|medical|clinical|nurse|nursing|midwife|midwifery|pharmacist|pharmacy|laboratory|lab|radiographer|radiography|physio|dental|dentist|optometrist|optometry|scientist|engineer)\b/', $normalized)) {
            return 'professional';
        }

        return 'others';
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

    protected function gradeLabel(?string $scaleCode, ?string $scaleName): string
    {
        $label = trim((string) ($scaleName ?: $scaleCode ?: ''));

        return $label !== '' ? mb_strtoupper($label) : 'GRADE';
    }

    protected function scaleNameFromLabel(?string $scale): ?string
    {
        if (! is_string($scale) || $scale === '') {
            return null;
        }

        if (str_contains($scale, ' - ')) {
            return trim(str($scale)->after(' - ')->toString());
        }

        return trim($scale);
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
