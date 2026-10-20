<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCommitment;
use App\Domain\Budget\Services\BudgetLedgerService;
use App\Domain\Procurement\Models\ProcurementPlan;
use App\Domain\Procurement\Models\ProcurementPlanLine;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\ProcurementRequisitionLine;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcurementRequisitionService
{
    public function __construct(
        private BudgetLedgerService $budgetLedgerService,
        private AuditLogService $auditLogService,
    ) {}

    public function createRequisition(array $data, ?User $actor = null): ProcurementRequisition
    {
        return DB::transaction(function () use ($data, $actor): ProcurementRequisition {
            if (! empty($data['procurement_plan_id'])) {
                $plan = ProcurementPlan::query()->where('mda_id', $data['mda_id'])->whereKey($data['procurement_plan_id'])->lockForUpdate()->firstOrFail();
                if (! in_array($plan->status, ['draft', 'approved'], true)) {
                    throw ValidationException::withMessages(['procurement_plan_id' => 'This procurement plan is closed.']);
                }
            }
            $requisition = ProcurementRequisition::query()->create([
                'mda_id' => $data['mda_id'],
                'procurement_plan_id' => $data['procurement_plan_id'] ?? null,
                'budget_cycle_id' => $data['budget_cycle_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'requisition_no' => $data['requisition_no'] ?? $this->nextRequisitionNumber((int) $data['mda_id']),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'justification' => $data['justification'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'requested_by' => $actor?->id ?? $data['requested_by'] ?? null,
            ]);

            $this->auditLogService->logCreated($requisition, ['source' => 'procurement_requisition.created']);

            return $requisition->fresh();
        });
    }

    public function addLine(ProcurementRequisition $requisition, array $data, ?User $actor = null): ProcurementRequisitionLine
    {
        return DB::transaction(function () use ($requisition, $data, $actor): ProcurementRequisitionLine {
            $requisition = ProcurementRequisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($requisition);

            $planLine = null;
            $allocation = null;

            if (! empty($data['procurement_plan_line_id'])) {
                $planLine = ProcurementPlanLine::query()
                    ->where('mda_id', $requisition->mda_id)
                    ->whereKey($data['procurement_plan_line_id'])
                    ->firstOrFail();
                $plan = $planLine->plan()->lockForUpdate()->firstOrFail();
                if (! in_array($plan->status, ['draft', 'approved'], true)) {
                    throw ValidationException::withMessages(['procurement_plan_line_id' => 'This plan is closed.']);
                }
                $allocation = $planLine->budgetAllocation;
            }

            if (! empty($data['budget_allocation_id'])) {
                $allocation = BudgetAllocation::query()
                    ->where('mda_id', $requisition->mda_id)
                    ->whereKey($data['budget_allocation_id'])
                    ->firstOrFail();
            }

            $estimatedAmount = round((float) ($data['estimated_amount'] ?? $planLine?->estimated_amount ?? 0), 2);
            if ($estimatedAmount <= 0) {
                throw ValidationException::withMessages(['estimated_amount' => 'Estimated amount must be greater than zero.']);
            }

            $line = $requisition->lines()->create([
                'mda_id' => $requisition->mda_id,
                'procurement_plan_line_id' => $planLine?->id,
                'budget_allocation_id' => $allocation?->id,
                'department_id' => $data['department_id'] ?? $planLine?->department_id ?? $allocation?->department_id ?? $requisition->department_id,
                'item_description' => $data['item_description'] ?? $planLine?->item_description,
                'specification' => $data['specification'] ?? $planLine?->specification,
                'quantity' => $data['quantity'] ?? $planLine?->quantity ?? 1,
                'unit' => $data['unit'] ?? $planLine?->unit,
                'estimated_unit_cost' => $data['estimated_unit_cost'] ?? $planLine?->estimated_unit_cost,
                'estimated_amount' => $estimatedAmount,
                'status' => $data['status'] ?? 'draft',
                'notes' => $data['notes'] ?? null,
                'metadata' => [
                    ...($data['metadata'] ?? []),
                    'created_by' => $actor?->id,
                    'source' => $planLine ? 'procurement_plan_line' : 'manual',
                ],
            ]);

            $this->refreshTotals($requisition);
            $this->auditLogService->logCreated($line, [
                'source' => 'procurement_requisition.line_created',
                'procurement_requisition_id' => $requisition->id,
            ]);

            return $line->fresh(['budgetAllocation', 'planLine']);
        });
    }

    public function submit(ProcurementRequisition $requisition, ?User $actor = null): ProcurementRequisition
    {
        return DB::transaction(function () use ($requisition, $actor): ProcurementRequisition {
            $requisition = ProcurementRequisition::query()->with('lines')->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

            if (! in_array($requisition->status, ['draft', 'returned'], true)) {
                throw ValidationException::withMessages(['requisition' => 'Only draft or returned requisitions can be submitted.']);
            }

            if ($requisition->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'A requisition must have at least one line before submission.']);
            }

            $before = $requisition->toArray();
            $requisition->forceFill([
                'status' => 'submitted',
                'submitted_by' => $actor?->id ?? $requisition->submitted_by,
                'submitted_at' => now(),
            ])->save();
            $requisition->lines()->update(['status' => 'submitted']);

            $this->auditLogService->logUpdated($requisition, $before, ['source' => 'procurement_requisition.submitted']);

            return $requisition->fresh(['lines']);
        });
    }

    /**
     * @return array{status: string, total_amount: float, available_amount: float, insufficient_lines: int, unmapped_lines: int}
     */
    public function reviewBudgetAvailability(ProcurementRequisition $requisition, ?User $actor = null): array
    {
        return DB::transaction(function () use ($requisition, $actor): array {
            $requisition = ProcurementRequisition::query()
                ->with(['lines.budgetAllocation'])
                ->whereKey($requisition->id)
                ->lockForUpdate()
                ->firstOrFail();

            $total = 0.0;
            $availableTotal = 0.0;
            $insufficientLines = 0;
            $unmappedLines = 0;
            $requestedByAllocation = [];

            foreach ($requisition->lines as $line) {
                $amount = round((float) $line->estimated_amount, 2);
                $total += $amount;

                if (! $line->budgetAllocation) {
                    $unmappedLines++;
                    $this->markLineBudgetCheck($line, 'unmapped', $amount, 0.0, []);

                    continue;
                }

                $balance = $this->budgetLedgerService->balancesForAllocation($line->budgetAllocation);
                $available = round((float) $balance['available_amount'], 2);
                $previouslyRequested = $requestedByAllocation[$line->budget_allocation_id] ?? 0.0;
                $remainingForLine = round($available - $previouslyRequested, 2);
                $availableTotal += max(0.0, $remainingForLine);
                $status = $remainingForLine >= $amount ? 'available' : 'insufficient';
                $requestedByAllocation[$line->budget_allocation_id] = round($previouslyRequested + $amount, 2);

                if ($status === 'insufficient') {
                    $insufficientLines++;
                }

                $this->markLineBudgetCheck($line, $status, $amount, $remainingForLine, $balance);
            }

            $reviewStatus = $unmappedLines > 0
                ? 'unmapped'
                : ($insufficientLines > 0 ? 'insufficient' : 'available');

            $before = $requisition->toArray();
            $requisition->forceFill([
                'budget_review_status' => $reviewStatus,
                'budget_reviewed_by' => $actor?->id ?? $requisition->budget_reviewed_by,
                'budget_reviewed_at' => now(),
                'summary' => [
                    ...($requisition->summary ?? []),
                    'budget_review' => [
                        'status' => $reviewStatus,
                        'total_amount' => round($total, 2),
                        'available_amount' => round($availableTotal, 2),
                        'insufficient_lines' => $insufficientLines,
                        'unmapped_lines' => $unmappedLines,
                        'reviewed_at' => now()->toISOString(),
                    ],
                ],
            ])->save();

            $this->auditLogService->logUpdated($requisition, $before, ['source' => 'procurement_requisition.budget_reviewed']);

            return [
                'status' => $reviewStatus,
                'total_amount' => round($total, 2),
                'available_amount' => round($availableTotal, 2),
                'insufficient_lines' => $insufficientLines,
                'unmapped_lines' => $unmappedLines,
            ];
        });
    }

    public function approve(ProcurementRequisition $requisition, ?User $actor = null): ProcurementRequisition
    {
        return DB::transaction(function () use ($requisition, $actor): ProcurementRequisition {
            $requisition = ProcurementRequisition::query()
                ->with(['lines.budgetAllocation'])
                ->whereKey($requisition->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($requisition->status === 'approved') {
                return $requisition->fresh(['lines.budgetCommitment']);
            }

            if (! in_array($requisition->status, ['submitted', 'budget_reviewed'], true)) {
                throw ValidationException::withMessages(['requisition' => 'Only submitted requisitions can be approved.']);
            }

            $review = $this->reviewBudgetAvailability($requisition, $actor);
            if ($review['status'] !== 'available') {
                throw ValidationException::withMessages(['budget' => 'This requisition cannot be approved until all lines have sufficient mapped budget.']);
            }

            $before = $requisition->toArray();
            foreach ($requisition->fresh('lines.budgetAllocation')->lines as $line) {
                $commitment = $this->commitmentForLine($line, $actor);
                $posted = $this->budgetLedgerService->postCommitment($commitment, $actor);
                $line->forceFill([
                    'budget_commitment_id' => $posted->id,
                    'status' => 'committed',
                ])->save();
            }

            $requisition->forceFill([
                'status' => 'approved',
                'budget_review_status' => 'available',
                'approved_by' => $actor?->id ?? $requisition->approved_by,
                'approved_at' => now(),
            ])->save();

            $this->auditLogService->logUpdated($requisition, $before, ['source' => 'procurement_requisition.approved']);

            return $requisition->fresh(['lines.budgetCommitment']);
        });
    }

    protected function commitmentForLine(ProcurementRequisitionLine $line, ?User $actor): BudgetCommitment
    {
        if (! $line->budgetAllocation) {
            throw ValidationException::withMessages(['budget_allocation_id' => 'Every approved requisition line must have a budget allocation.']);
        }

        $commitment = BudgetCommitment::query()->firstOrNew([
            'mda_id' => $line->mda_id,
            'source_type' => $line::class,
            'source_id' => $line->id,
        ]);

        if ($commitment->exists && $commitment->committed_at !== null) {
            return $commitment;
        }

        $commitment->fill([
            'budget_cycle_id' => $line->budgetAllocation->budget_cycle_id,
            'budget_allocation_id' => $line->budget_allocation_id,
            'commitment_no' => $commitment->commitment_no ?? $this->commitmentNumber($line),
            'description' => 'Procurement requisition '.$line->requisition->requisition_no.': '.$line->item_description,
            'amount' => round((float) $line->estimated_amount, 2),
            'status' => 'draft',
            'committed_by' => $actor?->id,
            'metadata' => [
                'procurement_requisition_id' => $line->procurement_requisition_id,
                'procurement_requisition_line_id' => $line->id,
                'procurement_plan_line_id' => $line->procurement_plan_line_id,
            ],
        ]);
        $commitment->save();

        return $commitment->fresh(['allocation']);
    }

    protected function markLineBudgetCheck(ProcurementRequisitionLine $line, string $status, float $requested, float $available, array $balance): void
    {
        $line->forceFill([
            'budget_check_status' => $status,
            'budget_check_summary' => [
                'requested_amount' => round($requested, 2),
                'available_amount' => round($available, 2),
                'balance' => $balance,
                'checked_at' => now()->toISOString(),
            ],
        ])->save();
    }

    protected function refreshTotals(ProcurementRequisition $requisition): void
    {
        $totals = ProcurementRequisitionLine::query()
            ->where('procurement_requisition_id', $requisition->id)
            ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(estimated_amount), 0) as total_amount')
            ->first();

        $requisition->forceFill([
            'total_amount' => round((float) ($totals?->total_amount ?? 0), 2),
            'summary' => [
                ...($requisition->summary ?? []),
                'line_count' => (int) ($totals?->line_count ?? 0),
                'total_amount' => round((float) ($totals?->total_amount ?? 0), 2),
                'refreshed_at' => now()->toISOString(),
            ],
        ])->save();
    }

    protected function assertEditable(ProcurementRequisition $requisition): void
    {
        if (! in_array($requisition->status, ['draft', 'returned'], true)) {
            throw ValidationException::withMessages(['requisition' => 'Only draft or returned requisitions can be edited.']);
        }
    }

    protected function nextRequisitionNumber(int $mdaId): string
    {
        $next = ProcurementRequisition::query()->where('mda_id', $mdaId)->count() + 1;

        return 'REQ-'.now()->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    protected function commitmentNumber(ProcurementRequisitionLine $line): string
    {
        return substr('PROC-'.$line->requisition->requisition_no.'-'.$line->id, 0, 80);
    }
}
