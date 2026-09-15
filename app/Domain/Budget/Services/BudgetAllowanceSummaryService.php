<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Models\BudgetWorkbook;
use Illuminate\Support\Collection;

class BudgetAllowanceSummaryService
{
    /**
     * Split only reconciled saved amounts; never recalculate an approved budget at today's rates.
     * A null result means the original inclusive figures must remain visible.
     */
    public function forWorkbook(BudgetWorkbook $workbook, Collection $budgetLines): ?Collection
    {
        if ($budgetLines->isEmpty()) {
            return collect();
        }

        $movement = $workbook->movementWorkbook;
        if (! $movement || (int) $movement->mda_id !== (int) $workbook->mda_id) {
            return null;
        }

        $aggregates = [];
        foreach ($movement->lines()->with(['staff', 'currentEmployment'])->lazyById(200) as $line) {
            $departmentId = $line->currentEmployment?->department_id;

            if ($line->countsAsCurrentStaff()) {
                $this->addSnapshot(
                    $aggregates,
                    $this->key($departmentId, $line->current_salary_scale_id, $line->current_level),
                    'current',
                    $line->current_amounts ?? [],
                );
            }

            if ($line->countsAsRequiredStaff()) {
                $this->addSnapshot(
                    $aggregates,
                    $this->key(
                        $departmentId,
                        $line->proposed_salary_scale_id ?? $line->current_salary_scale_id,
                        $line->proposed_level ?? $line->current_level,
                    ),
                    'proposed',
                    $line->proposed_amounts ?? [],
                );
            }
        }

        $result = collect();
        foreach ($budgetLines as $line) {
            $aggregate = $aggregates[$this->key($line->department_id, $line->salary_scale_id, $line->level)] ?? $this->emptyAggregate();
            $requiredStaff = $line->required_staff_count ?? max(0, (int) $line->staff_count - (int) $line->retiring_count);
            $currentAllowance = null;
            $proposedAllowance = null;

            if ($aggregate['current_complete']
                && $aggregate['current_count'] === (int) $line->staff_count
                && $aggregate['current_gross'] === $this->cents($line->current_gross_total)) {
                $currentAllowance = $aggregate['current'] / 100;
            }

            if ($aggregate['proposed_complete']
                && $aggregate['proposed_count'] === (int) $requiredStaff
                && $aggregate['proposed_gross'] === $this->cents($line->proposed_gross_total)) {
                $proposedAllowance = $aggregate['proposed'] / 100;
            }

            if ($currentAllowance !== null || $proposedAllowance !== null) {
                $result->put($line->id, [
                    'current' => $currentAllowance,
                    'proposed' => $proposedAllowance,
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, int|bool>>  $aggregates
     * @param  array<string, mixed>  $amounts
     */
    protected function addSnapshot(array &$aggregates, string $key, string $period, array $amounts): void
    {
        $aggregates[$key] ??= $this->emptyAggregate();

        $gross = $this->cents($amounts['calculated_gross'] ?? 0);
        $aggregates[$key][$period.'_count']++;
        $aggregates[$key][$period.'_gross'] += $gross;

        // Unpriced/zero snapshots contributed nothing to the saved budget total.
        if ($gross === 0) {
            return;
        }

        if (! is_numeric($amounts['basic_salary'] ?? null) || ! is_numeric($amounts['total_allowances'] ?? null)) {
            $aggregates[$key][$period.'_complete'] = false;

            return;
        }

        $basic = $this->cents($amounts['basic_salary']);
        $allowances = $this->cents($amounts['total_allowances']);

        if ($basic < 0 || $allowances < 0 || $basic + $allowances !== $gross) {
            $aggregates[$key][$period.'_complete'] = false;

            return;
        }

        $aggregates[$key][$period] += $allowances;
    }

    /**
     * @return array<string, int|bool>
     */
    protected function emptyAggregate(): array
    {
        return [
            'current_count' => 0,
            'proposed_count' => 0,
            'current_gross' => 0,
            'proposed_gross' => 0,
            'current' => 0,
            'proposed' => 0,
            'current_complete' => true,
            'proposed_complete' => true,
        ];
    }

    protected function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    protected function key(?int $departmentId, ?int $scaleId, ?int $level): string
    {
        return implode('|', [$departmentId ?? 0, $scaleId ?? 0, $level ?? 0]);
    }
}
