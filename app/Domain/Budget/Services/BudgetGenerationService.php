<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Models\BudgetLine;
use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BudgetGenerationService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected BudgetWorkbookWorkflowService $budgetWorkflowService,
    ) {}

    public function generateFromMovementWorkbook(MovementWorkbook $movementWorkbook, ?int $generatedBy = null): BudgetWorkbook
    {
        if (! in_array($movementWorkbook->status, ['approved', 'locked'], true)) {
            throw new InvalidArgumentException('Budget workbooks can only be generated from approved or locked movement workbooks.');
        }

        return DB::transaction(function () use ($movementWorkbook, $generatedBy): BudgetWorkbook {
            $budgetWorkbook = BudgetWorkbook::query()->updateOrCreate(
                [
                    'movement_workbook_id' => $movementWorkbook->id,
                    'mda_id' => $movementWorkbook->mda_id,
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

            $budgetWorkbook = $this->budgetWorkflowService->resetApprovalAfterRegeneration($budgetWorkbook);
            $budgetWorkbook->lines()->delete();

            $totals = [
                'line_count' => 0,
                'staff_count' => 0,
                'required_staff_count' => 0,
                'current_gross_total' => 0.0,
                'proposed_gross_total' => 0.0,
                'variance_total' => 0.0,
            ];

            foreach ($this->budgetLinePayloads($movementWorkbook) as $payload) {
                BudgetLine::query()->create([
                    'workbook_id' => $budgetWorkbook->id,
                    ...$payload,
                ]);

                $totals['line_count']++;
                $totals['staff_count'] += (int) $payload['staff_count'];
                $totals['required_staff_count'] += (int) ($payload['required_staff_count'] ?? max(0, (int) $payload['staff_count'] - (int) $payload['retiring_count']));
                $totals['current_gross_total'] += (float) $payload['current_gross_total'];
                $totals['proposed_gross_total'] += (float) $payload['proposed_gross_total'];
                $totals['variance_total'] += (float) $payload['variance_total'];
            }

            $before = $budgetWorkbook->toArray();

            $budgetWorkbook->forceFill([
                'summary' => [
                    'line_count' => $totals['line_count'],
                    'staff_count' => $totals['staff_count'],
                    'required_staff_count' => $totals['required_staff_count'],
                    'current_gross_total' => round($totals['current_gross_total'], 2),
                    'proposed_gross_total' => round($totals['proposed_gross_total'], 2),
                    'variance_total' => round($totals['variance_total'], 2),
                ],
            ])->save();

            $this->auditLogService->logUpdated($budgetWorkbook, $before, ['source' => 'budget_generation']);

            return $budgetWorkbook->fresh(['lines', 'movementWorkbook', 'mda']);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function budgetLinePayloads(MovementWorkbook $movementWorkbook): array
    {
        if ($movementWorkbook->lines()->exists()) {
            return $this->budgetLinePayloadsFromMovementLines($movementWorkbook);
        }

        return $this->budgetLinePayloadsFromSummaries($movementWorkbook);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function budgetLinePayloadsFromMovementLines(MovementWorkbook $movementWorkbook): array
    {
        $aggregates = [];

        $movementWorkbook->lines()
            ->with(['staff', 'currentEmployment'])
            ->chunkById(200, function ($lines) use (&$aggregates): void {
                foreach ($lines as $line) {
                    if (! $line->countsAsCurrentStaff()) {
                        continue;
                    }

                    $departmentId = $line->currentEmployment?->department_id;
                    $currentSalaryScaleId = $line->current_salary_scale_id;
                    $currentLevel = $line->current_level;
                    $currentKey = $this->lineKey($departmentId, $currentSalaryScaleId, $currentLevel);

                    $this->initializeAggregate($aggregates, $currentKey, $departmentId, $currentSalaryScaleId, $currentLevel);
                    $aggregates[$currentKey]['staff_count']++;
                    $aggregates[$currentKey]['retiring_count'] += $line->retirement_status === 'retiring' && ! $line->hasMovementOverride() ? 1 : 0;
                    $aggregates[$currentKey]['current_gross_total'] += (float) ($line->current_amounts['calculated_gross'] ?? 0);

                    if (! $line->countsAsRequiredStaff()) {
                        continue;
                    }

                    $proposedSalaryScaleId = $line->proposed_salary_scale_id ?? $currentSalaryScaleId;
                    $proposedLevel = $line->proposed_level ?? $currentLevel;
                    $proposedKey = $this->lineKey($departmentId, $proposedSalaryScaleId, $proposedLevel);

                    $this->initializeAggregate($aggregates, $proposedKey, $departmentId, $proposedSalaryScaleId, $proposedLevel);
                    $aggregates[$proposedKey]['required_staff_count']++;
                    $aggregates[$proposedKey]['proposed_gross_total'] += (float) ($line->proposed_amounts['calculated_gross'] ?? 0);
                }
            });

        return array_values(array_map(function (array $aggregate): array {
            $aggregate['current_gross_total'] = round($aggregate['current_gross_total'], 2);
            $aggregate['proposed_gross_total'] = round($aggregate['proposed_gross_total'], 2);
            $aggregate['variance_total'] = round($aggregate['proposed_gross_total'] - $aggregate['current_gross_total'], 2);

            return $aggregate;
        }, $aggregates));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function budgetLinePayloadsFromSummaries(MovementWorkbook $movementWorkbook): array
    {
        return $movementWorkbook->summaries->map(fn ($summary): array => [
            'department_id' => $summary->department_id,
            'salary_scale_id' => $summary->salary_scale_id,
            'level' => $summary->level,
            'staff_count' => $summary->staff_count,
            'retiring_count' => $summary->retiring_count,
            'required_staff_count' => null,
            'current_gross_total' => $summary->current_gross_total,
            'proposed_gross_total' => $summary->proposed_gross_total,
            'variance_total' => $summary->variance_total,
        ])->values()->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $aggregates
     */
    protected function initializeAggregate(array &$aggregates, string $key, ?int $departmentId, ?int $salaryScaleId, ?int $level): void
    {
        $aggregates[$key] ??= [
            'department_id' => $departmentId,
            'salary_scale_id' => $salaryScaleId,
            'level' => $level,
            'staff_count' => 0,
            'retiring_count' => 0,
            'required_staff_count' => 0,
            'current_gross_total' => 0.0,
            'proposed_gross_total' => 0.0,
            'variance_total' => 0.0,
        ];
    }

    protected function lineKey(?int $departmentId, ?int $salaryScaleId, ?int $level): string
    {
        return implode('|', [$departmentId ?? 0, $salaryScaleId ?? 0, $level ?? 0]);
    }
}
