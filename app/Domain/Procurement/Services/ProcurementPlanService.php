<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Procurement\Models\ProcurementPlan;
use App\Domain\Procurement\Models\ProcurementPlanLine;
use App\Domain\Procurement\Models\ProcurementRequisitionLine;
use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanActivity;
use App\Enums\WorkplanStatus;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcurementPlanService
{
    protected const IMPORTABLE_BUDGET_ACCOUNT_TYPES = [
        'capital',
        'capital_expenditure',
        'overhead',
        'overhead_expenditure',
    ];

    public function __construct(private AuditLogService $auditLogService) {}

    public function createPlan(array $data, ?User $actor = null): ProcurementPlan
    {
        $plan = ProcurementPlan::query()->create([
            'mda_id' => $data['mda_id'],
            'budget_cycle_id' => $data['budget_cycle_id'] ?? null,
            'year' => $data['year'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'source_type' => $data['source_type'] ?? 'manual',
            'prepared_by' => $actor?->id ?? $data['prepared_by'] ?? null,
        ]);

        $this->auditLogService->logCreated($plan, ['source' => 'procurement_plan.created']);

        return $plan->fresh();
    }

    /**
     * @return array{lines_synced: int, estimated_amount: float}
     */
    public function importApprovedWorkplanActivities(ProcurementPlan $plan, Workplan $workplan, ?User $actor = null): array
    {
        $this->assertSameMda($plan, $workplan, 'This workplan belongs to another MDA.');
        $this->assertSameYear($plan->year, $workplan->year, 'This workplan belongs to another planning year.');

        if (! in_array($this->workplanStatus($workplan), [WorkplanStatus::APPROVED->value, WorkplanStatus::ACTIVE->value], true)) {
            throw ValidationException::withMessages([
                'workplan' => 'Only approved workplans can feed procurement planning.',
            ]);
        }

        return DB::transaction(function () use ($plan, $workplan, $actor): array {
            $plan = $this->lockEditable($plan);
            $count = 0;
            $amount = 0.0;

            WorkplanActivity::query()
                ->where('mda_id', $plan->mda_id)
                ->whereHas('objective', fn (Builder $query): Builder => $query->where('workplan_id', $workplan->id))
                ->with(['department'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->each(function (WorkplanActivity $activity) use ($plan, $actor, &$count, &$amount): void {
                    $estimatedAmount = round((float) $activity->planned_cost, 2);

                    if ($estimatedAmount <= 0) {
                        return;
                    }

                    $this->syncSourceLine($plan, $activity, [
                        'department_id' => $activity->department_id,
                        'workplan_activity_id' => $activity->id,
                        'budget_allocation_id' => null,
                        'item_description' => $activity->title,
                        'specification' => $activity->expected_output ?? $activity->description,
                        'quantity' => 1,
                        'unit' => 'lot',
                        'estimated_unit_cost' => $estimatedAmount,
                        'estimated_amount' => $estimatedAmount,
                        'procurement_method' => $this->defaultProcurementMethod($estimatedAmount),
                        'planned_start_date' => $activity->start_date,
                        'planned_end_date' => $activity->end_date,
                        'status' => 'planned',
                        'metadata' => [
                            'source' => 'workplan_activity',
                            'activity_code' => $activity->activity_code,
                            'funding_source' => $activity->funding_source,
                            'synced_by' => $actor?->id,
                        ],
                    ]);

                    $count++;
                    $amount += $estimatedAmount;
                });

            $this->refreshSummary($plan);
            $this->logImport($plan, 'procurement_plan.workplan_imported', $count, $amount, ['workplan_id' => $workplan->id]);

            return ['lines_synced' => $count, 'estimated_amount' => round($amount, 2)];
        });
    }

    /**
     * @return array{lines_synced: int, estimated_amount: float}
     */
    public function importApprovedBudgetAllocations(ProcurementPlan $plan, ?BudgetCycle $cycle = null, ?User $actor = null): array
    {
        if ($cycle) {
            $this->assertSameMda($plan, $cycle, 'This budget cycle belongs to another MDA.');
            $this->assertSameYear($plan->year, $cycle->year, 'This budget cycle belongs to another planning year.');
        }

        return DB::transaction(function () use ($plan, $cycle, $actor): array {
            $plan = $this->lockEditable($plan);
            $count = 0;
            $amount = 0.0;

            BudgetAllocation::query()
                ->with(['account', 'department'])
                ->where('mda_id', $plan->mda_id)
                ->where('status', 'approved')
                ->when(
                    $cycle,
                    fn (Builder $query): Builder => $query->where('budget_cycle_id', $cycle->id),
                    fn (Builder $query): Builder => $plan->budget_cycle_id
                        ? $query->where('budget_cycle_id', $plan->budget_cycle_id)
                        : $query->whereHas('cycle', fn (Builder $cycleQuery): Builder => $cycleQuery->where('year', $plan->year))
                )
                ->whereHas('account', fn (Builder $query): Builder => $query->whereIn('account_type', self::IMPORTABLE_BUDGET_ACCOUNT_TYPES))
                ->orderBy('id')
                ->get()
                ->each(function (BudgetAllocation $allocation) use ($plan, $actor, &$count, &$amount): void {
                    $estimatedAmount = round((float) $allocation->approved_amount, 2);

                    if ($estimatedAmount <= 0) {
                        return;
                    }

                    $this->syncSourceLine($plan, $allocation, [
                        'department_id' => $allocation->department_id,
                        'budget_allocation_id' => $allocation->id,
                        'workplan_activity_id' => $allocation->workplan_activity_id,
                        'item_description' => $allocation->title,
                        'specification' => $allocation->description,
                        'quantity' => 1,
                        'unit' => 'lot',
                        'estimated_unit_cost' => $estimatedAmount,
                        'estimated_amount' => $estimatedAmount,
                        'procurement_method' => $this->defaultProcurementMethod($estimatedAmount),
                        'status' => 'planned',
                        'metadata' => [
                            'source' => 'budget_allocation',
                            'budget_account_id' => $allocation->budget_account_id,
                            'budget_account_code' => $allocation->account?->code,
                            'budget_account_type' => $allocation->account?->account_type,
                            'synced_by' => $actor?->id,
                        ],
                    ]);

                    $count++;
                    $amount += $estimatedAmount;
                });

            $this->refreshSummary($plan);
            $this->logImport($plan, 'procurement_plan.budget_allocations_imported', $count, $amount, [
                'budget_cycle_id' => $cycle?->id ?? $plan->budget_cycle_id,
            ]);

            return ['lines_synced' => $count, 'estimated_amount' => round($amount, 2)];
        });
    }

    public function addManualLine(ProcurementPlan $plan, array $data, ?User $actor = null): ProcurementPlanLine
    {
        return DB::transaction(function () use ($plan, $data, $actor): ProcurementPlanLine {
            $plan = $this->lockEditable($plan);
            $allocation = null;

            if (! empty($data['budget_allocation_id'])) {
                $allocation = BudgetAllocation::query()
                    ->where('mda_id', $plan->mda_id)
                    ->whereKey($data['budget_allocation_id'])
                    ->firstOrFail();
            }

            $estimatedAmount = round((float) ($data['estimated_amount'] ?? 0), 2);

            if ($estimatedAmount <= 0) {
                throw ValidationException::withMessages(['estimated_amount' => 'Estimated amount must be greater than zero.']);
            }

            $line = $plan->lines()->create([
                'mda_id' => $plan->mda_id,
                'department_id' => $data['department_id'] ?? $allocation?->department_id,
                'budget_allocation_id' => $allocation?->id,
                'workplan_activity_id' => $data['workplan_activity_id'] ?? $allocation?->workplan_activity_id,
                'item_description' => $data['item_description'],
                'specification' => $data['specification'] ?? null,
                'quantity' => $data['quantity'] ?? 1,
                'unit' => $data['unit'] ?? null,
                'estimated_unit_cost' => $data['estimated_unit_cost'] ?? null,
                'estimated_amount' => $estimatedAmount,
                'procurement_method' => $data['procurement_method'] ?? $this->defaultProcurementMethod($estimatedAmount),
                'planned_start_date' => $data['planned_start_date'] ?? null,
                'planned_end_date' => $data['planned_end_date'] ?? null,
                'status' => $data['status'] ?? 'planned',
                'priority' => $data['priority'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => [
                    ...($data['metadata'] ?? []),
                    'source' => 'manual',
                    'created_by' => $actor?->id,
                ],
            ]);

            $this->refreshSummary($plan);
            $this->auditLogService->logCreated($line, [
                'source' => 'procurement_plan.manual_line_created',
                'procurement_plan_id' => $plan->id,
                'mda_id' => $plan->mda_id,
            ]);

            return $line->fresh(['budgetAllocation', 'workplanActivity']);
        });
    }

    protected function syncSourceLine(ProcurementPlan $plan, Model $source, array $data): ProcurementPlanLine
    {
        $line = ProcurementPlanLine::query()->firstOrNew([
            'procurement_plan_id' => $plan->id,
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
        ]);
        $before = $line->exists ? $line->toArray() : [];

        $line->fill([
            'mda_id' => $plan->mda_id,
            ...$data,
        ]);
        if ($line->exists && $line->isDirty(array_diff(array_keys($data), ['metadata']))
            && ProcurementRequisitionLine::query()->where('mda_id', $plan->mda_id)->where('procurement_plan_line_id', $line->id)->exists()) {
            throw ValidationException::withMessages(['plan' => 'An imported line is already used by a requisition. Add a new plan line instead.']);
        }
        $line->save();

        if ($before === []) {
            $this->auditLogService->logCreated($line, [
                'source' => 'procurement_plan.source_line_created',
                'procurement_plan_id' => $plan->id,
            ]);
        } else {
            $this->auditLogService->logUpdated($line, $before, [
                'source' => 'procurement_plan.source_line_updated',
                'procurement_plan_id' => $plan->id,
            ]);
        }

        return $line->fresh();
    }

    private function lockEditable(ProcurementPlan $plan): ProcurementPlan
    {
        $plan = ProcurementPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
        if (! in_array($plan->status, ['draft', 'approved'], true)) {
            throw ValidationException::withMessages(['plan' => 'This plan is no longer editable.']);
        }

        return $plan;
    }

    protected function refreshSummary(ProcurementPlan $plan): void
    {
        $totals = ProcurementPlanLine::query()
            ->where('procurement_plan_id', $plan->id)
            ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(estimated_amount), 0) as estimated_amount')
            ->first();

        $plan->forceFill([
            'summary' => [
                'line_count' => (int) ($totals?->line_count ?? 0),
                'estimated_amount' => round((float) ($totals?->estimated_amount ?? 0), 2),
                'refreshed_at' => now()->toISOString(),
            ],
        ])->save();
    }

    protected function defaultProcurementMethod(float $estimatedAmount): string
    {
        return $estimatedAmount >= 5000000 ? 'open_tender' : 'request_for_quotation';
    }

    protected function workplanStatus(Workplan $workplan): string
    {
        return $workplan->status instanceof WorkplanStatus ? $workplan->status->value : (string) $workplan->status;
    }

    protected function assertSameMda(ProcurementPlan $plan, Model $source, string $message): void
    {
        if ((int) $plan->mda_id !== (int) $source->getAttribute('mda_id')) {
            throw ValidationException::withMessages(['mda_id' => $message]);
        }
    }

    protected function assertSameYear(int $planYear, int $sourceYear, string $message): void
    {
        if ((int) $planYear !== (int) $sourceYear) {
            throw ValidationException::withMessages(['year' => $message]);
        }
    }

    protected function logImport(ProcurementPlan $plan, string $eventCode, int $count, float $amount, array $context = []): void
    {
        $this->auditLogService->log($eventCode, $plan, [], [
            'lines_synced' => $count,
            'estimated_amount' => round($amount, 2),
        ], [
            'mda_id' => $plan->mda_id,
            'year' => $plan->year,
            ...$context,
        ]);
    }
}
