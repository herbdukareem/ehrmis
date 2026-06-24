<?php

namespace App\Domain\Movement\Services;

use App\Domain\Movement\Models\MovementSummary;
use App\Domain\Movement\Models\MovementWorkbook;

class MovementSummaryService
{
    public function regenerate(MovementWorkbook $workbook): void
    {
        $aggregates = [];

        $workbook->lines()
            ->with('currentEmployment')
            ->chunkById(200, function ($lines) use (&$aggregates): void {
                foreach ($lines as $line) {
                    $departmentId = $line->currentEmployment?->department_id;
                    $salaryScaleId = $line->current_salary_scale_id;
                    $level = $line->current_level;
                    $key = implode('|', [$departmentId ?? 0, $salaryScaleId ?? 0, $level ?? 0]);

                    $aggregates[$key] ??= [
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

                    $aggregates[$key]['staff_count']++;
                    $aggregates[$key]['due_count'] += $line->eligibility_status === 'due' ? 1 : 0;
                    $aggregates[$key]['retiring_count'] += $line->retirement_status === 'retiring' ? 1 : 0;
                    $aggregates[$key]['retired_count'] += $line->retirement_status === 'retired' ? 1 : 0;
                    $aggregates[$key]['blocked_count'] += $line->eligibility_status === 'blocked_by_policy' ? 1 : 0;

                    $currentGross = (float) ($line->current_amounts['calculated_gross'] ?? 0);
                    $proposedGross = (float) ($line->proposed_amounts['calculated_gross'] ?? 0);
                    $aggregates[$key]['current_gross_total'] += $currentGross;
                    $aggregates[$key]['proposed_gross_total'] += $proposedGross;
                    $aggregates[$key]['variance_total'] += ($proposedGross - $currentGross);
                }
            });

        $workbook->summaries()->delete();

        if ($aggregates === []) {
            return;
        }

        $now = now();

        MovementSummary::query()->insert(array_map(
            fn (array $aggregate): array => [
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
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $aggregates,
        ));
    }
}
