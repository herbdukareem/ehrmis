<?php

namespace App\Domain\Movement\Services;

use App\Domain\Movement\Models\MovementSummary;
use App\Domain\Movement\Models\MovementWorkbook;

class MovementSummaryService
{
    public function regenerate(MovementWorkbook $workbook): void
    {
        $lines = $workbook->lines()
            ->with(['currentEmployment.department', 'currentSalaryScale'])
            ->get();

        $aggregates = [];

        foreach ($lines as $line) {
            $departmentId = $line->currentEmployment?->department_id;
            $salaryScaleId = $line->current_salary_scale_id;
            $level = $line->current_level;
            $key = implode('|', [$departmentId ?? 0, $salaryScaleId ?? 0, $level ?? 0]);

            if (! isset($aggregates[$key])) {
                $aggregates[$key] = [
                    'department_id' => $departmentId,
                    'salary_scale_id' => $salaryScaleId,
                    'level' => $level,
                    'staff_count' => 0,
                    'due_count' => 0,
                    'retiring_count' => 0,
                    'retired_count' => 0,
                    'blocked_count' => 0,
                    'current_gross_total' => 0.0,
                    'proposed_gross_total' => 0.0,
                    'variance_total' => 0.0,
                ];
            }

            $aggregates[$key]['staff_count']++;

            if ($line->eligibility_status === 'due') {
                $aggregates[$key]['due_count']++;
            }

            if ($line->retirement_status === 'retiring') {
                $aggregates[$key]['retiring_count']++;
            }

            if ($line->retirement_status === 'retired') {
                $aggregates[$key]['retired_count']++;
            }

            if ($line->eligibility_status === 'blocked_by_policy') {
                $aggregates[$key]['blocked_count']++;
            }

            $currentGross = (float) ($line->current_amounts['calculated_gross'] ?? 0);
            $proposedGross = (float) ($line->proposed_amounts['calculated_gross'] ?? 0);

            $aggregates[$key]['current_gross_total'] += $currentGross;
            $aggregates[$key]['proposed_gross_total'] += $proposedGross;
            $aggregates[$key]['variance_total'] += ($proposedGross - $currentGross);
        }

        $workbook->summaries()->delete();

        foreach ($aggregates as $aggregate) {
            MovementSummary::query()->create([
                'workbook_id' => $workbook->id,
                'department_id' => $aggregate['department_id'],
                'salary_scale_id' => $aggregate['salary_scale_id'],
                'level' => $aggregate['level'],
                'staff_count' => $aggregate['staff_count'],
                'due_count' => $aggregate['due_count'],
                'retiring_count' => $aggregate['retiring_count'],
                'retired_count' => $aggregate['retired_count'],
                'blocked_count' => $aggregate['blocked_count'],
                'current_gross_total' => round($aggregate['current_gross_total'], 2),
                'proposed_gross_total' => round($aggregate['proposed_gross_total'], 2),
                'variance_total' => round($aggregate['variance_total'], 2),
            ]);
        }
    }
}
