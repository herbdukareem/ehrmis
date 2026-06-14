<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Models\BudgetLine;
use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Services\AuditLogService;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

class BudgetGenerationService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {
    }

    public function generateFromMovementWorkbook(MovementWorkbook $movementWorkbook, ?int $generatedBy = null): BudgetWorkbook
    {
        if (! in_array($movementWorkbook->status, ['approved', 'locked'], true)) {
            throw new InvalidArgumentException('Budget workbooks can only be generated from approved or locked movement workbooks.');
        }

        return DB::transaction(function () use ($movementWorkbook, $generatedBy): BudgetWorkbook {
            $budgetWorkbook = BudgetWorkbook::query()->updateOrCreate(
                [
                    'movement_workbook_id' => $movementWorkbook->id,
                ],
                [
                    'mda_id' => $movementWorkbook->mda_id,
                    'year' => $movementWorkbook->year,
                    'status' => 'draft',
                    'generated_by' => $generatedBy,
                    'generated_at' => now(),
                    'locked_at' => null,
                ],
            );

            $budgetWorkbook->lines()->delete();

            $totals = [
                'line_count' => 0,
                'staff_count' => 0,
                'current_gross_total' => 0.0,
                'proposed_gross_total' => 0.0,
                'variance_total' => 0.0,
            ];

            foreach ($movementWorkbook->summaries as $summary) {
                BudgetLine::query()->create([
                    'workbook_id' => $budgetWorkbook->id,
                    'department_id' => $summary->department_id,
                    'salary_scale_id' => $summary->salary_scale_id,
                    'level' => $summary->level,
                    'staff_count' => $summary->staff_count,
                    'retiring_count' => $summary->retiring_count,
                    'current_gross_total' => $summary->current_gross_total,
                    'proposed_gross_total' => $summary->proposed_gross_total,
                    'variance_total' => $summary->variance_total,
                ]);

                $totals['line_count']++;
                $totals['staff_count'] += (int) $summary->staff_count;
                $totals['current_gross_total'] += (float) $summary->current_gross_total;
                $totals['proposed_gross_total'] += (float) $summary->proposed_gross_total;
                $totals['variance_total'] += (float) $summary->variance_total;
            }

            $before = $budgetWorkbook->toArray();

            $budgetWorkbook->forceFill([
                'summary' => [
                    'line_count' => $totals['line_count'],
                    'staff_count' => $totals['staff_count'],
                    'current_gross_total' => round($totals['current_gross_total'], 2),
                    'proposed_gross_total' => round($totals['proposed_gross_total'], 2),
                    'variance_total' => round($totals['variance_total'], 2),
                ],
            ])->save();

            $this->auditLogService->logUpdated($budgetWorkbook, $before, ['source' => 'budget_generation']);

            return $budgetWorkbook->fresh(['lines', 'movementWorkbook', 'mda']);
        });
    }
}
