<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Models\BudgetAccount;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Budget\Models\BudgetLine;
use App\Domain\Budget\Models\BudgetTransaction;
use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Organization\Models\MdaSetting;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecurrentBudgetAllocationSyncService
{
    protected const PERSONNEL_ACCOUNT_CODE = 'PERSONNEL';
    protected const PERSONNEL_ACCOUNT_NAME = 'Personnel Expenditure';

    public function __construct(private AuditLogService $auditLogService) {}

    public function shouldSync(BudgetWorkbook $workbook): bool
    {
        return (bool) MdaSetting::query()
            ->where('mda_id', $workbook->mda_id)
            ->value('link_recurrent_budget_to_annual_allocations');
    }

    /**
     * @return array{enabled: bool, cycle_id: int|null, account_id: int|null, allocations_synced: int, amount_synced: float}
     */
    public function syncApprovedWorkbook(BudgetWorkbook $workbook, ?User $actor = null): array
    {
        if (! $this->shouldSync($workbook)) {
            return [
                'enabled' => false,
                'cycle_id' => null,
                'account_id' => null,
                'allocations_synced' => 0,
                'amount_synced' => 0.0,
            ];
        }

        if (! in_array($workbook->status, ['approved', 'locked'], true)) {
            throw ValidationException::withMessages([
                'budget' => 'Only approved recurrent budget workbooks can be linked to annual allocations.',
            ]);
        }

        return DB::transaction(function () use ($workbook, $actor): array {
            $workbook = BudgetWorkbook::query()
                ->with(['lines.department', 'lines.salaryScale'])
                ->where('mda_id', $workbook->mda_id)
                ->whereKey($workbook->id)
                ->lockForUpdate()
                ->firstOrFail();

            $cycle = $this->budgetCycle($workbook, $actor);
            $account = $this->personnelAccount($workbook);
            $count = 0;
            $amount = 0.0;

            foreach ($workbook->lines as $line) {
                $lineAmount = round((float) $line->proposed_gross_total, 2);

                if ($lineAmount <= 0) {
                    continue;
                }

                $allocation = $this->syncAllocation($workbook, $line, $cycle, $account, $lineAmount, $actor);
                $this->syncAllocationLedger($allocation, $lineAmount, $actor);

                $count++;
                $amount += $lineAmount;
            }

            $cycle->forceFill([
                'summary' => [
                    ...($cycle->summary ?? []),
                    'personnel_allocation_total' => round($amount, 2),
                    'personnel_allocation_count' => $count,
                    'personnel_source_workbook_id' => $workbook->id,
                    'personnel_synced_at' => now()->toISOString(),
                ],
            ])->save();

            $this->auditLogService->log('budget.recurrent_allocations_synced', $workbook, [], [
                'cycle_id' => $cycle->id,
                'account_id' => $account->id,
                'allocations_synced' => $count,
                'amount_synced' => round($amount, 2),
            ], [
                'mda_id' => $workbook->mda_id,
                'year' => $workbook->year,
                'source' => 'recurrent_budget_link',
            ]);

            return [
                'enabled' => true,
                'cycle_id' => $cycle->id,
                'account_id' => $account->id,
                'allocations_synced' => $count,
                'amount_synced' => round($amount, 2),
            ];
        });
    }

    protected function budgetCycle(BudgetWorkbook $workbook, ?User $actor): BudgetCycle
    {
        return BudgetCycle::query()->firstOrCreate(
            [
                'mda_id' => $workbook->mda_id,
                'year' => $workbook->year,
            ],
            [
                'name' => "{$workbook->year} Annual Budget",
                'status' => 'open',
                'created_by' => $actor?->id ?? $workbook->approved_by,
                'approved_by' => $actor?->id ?? $workbook->approved_by,
                'approved_at' => now(),
                'opened_at' => now(),
            ],
        );
    }

    protected function personnelAccount(BudgetWorkbook $workbook): BudgetAccount
    {
        return BudgetAccount::query()->firstOrCreate(
            [
                'mda_id' => $workbook->mda_id,
                'code' => self::PERSONNEL_ACCOUNT_CODE,
            ],
            [
                'name' => self::PERSONNEL_ACCOUNT_NAME,
                'account_type' => 'personnel',
                'description' => 'Personnel expenditure imported from approved recurrent budget workbooks.',
                'status' => 'active',
            ],
        );
    }

    protected function syncAllocation(
        BudgetWorkbook $workbook,
        BudgetLine $line,
        BudgetCycle $cycle,
        BudgetAccount $account,
        float $amount,
        ?User $actor,
    ): BudgetAllocation {
        $allocation = BudgetAllocation::query()->firstOrNew([
            'mda_id' => $workbook->mda_id,
            'source_type' => $line::class,
            'source_id' => $line->id,
        ]);

        $allocation->fill([
            'budget_cycle_id' => $cycle->id,
            'budget_account_id' => $account->id,
            'department_id' => $line->department_id,
            'title' => $this->allocationTitle($line),
            'description' => 'Personnel expenditure allocation synced from approved recurrent budget workbook #'.$workbook->id.'.',
            'approved_amount' => $amount,
            'status' => 'approved',
            'allocated_by' => $allocation->allocated_by ?? $actor?->id ?? $workbook->approved_by,
            'approved_by' => $actor?->id ?? $workbook->approved_by,
            'approved_at' => now(),
        ]);
        $allocation->save();

        return $allocation->fresh();
    }

    protected function syncAllocationLedger(BudgetAllocation $allocation, float $amount, ?User $actor): BudgetTransaction
    {
        $minimumRequired = $this->minimumRequiredAllocation($allocation);

        if ($amount < $minimumRequired) {
            throw ValidationException::withMessages([
                'budget' => 'This personnel allocation cannot be reduced below committed or spent funds.',
            ]);
        }

        $transaction = BudgetTransaction::query()->firstOrNew([
            'transaction_type' => BudgetLedgerService::TYPE_ALLOCATION,
            'source_type' => $allocation::class,
            'source_id' => $allocation->id,
        ]);
        $before = $transaction->exists ? $transaction->toArray() : [];

        $transaction->fill([
            'mda_id' => $allocation->mda_id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'budget_allocation_id' => $allocation->id,
            'budget_account_id' => $allocation->budget_account_id,
            'department_id' => $allocation->department_id,
            'amount' => $amount,
            'signed_amount' => $amount,
            'transaction_date' => now()->toDateString(),
            'description' => 'Personnel expenditure allocation synced from recurrent budget',
            'recorded_by' => $actor?->id,
        ]);
        $transaction->save();

        if ($before === []) {
            $this->auditLogService->logCreated($transaction, [
                'source' => 'recurrent_budget_link.allocation_transaction_created',
                'budget_allocation_id' => $allocation->id,
            ]);
        } else {
            $this->auditLogService->logUpdated($transaction, $before, [
                'source' => 'recurrent_budget_link.allocation_transaction_updated',
                'budget_allocation_id' => $allocation->id,
            ]);
        }

        return $transaction;
    }

    protected function minimumRequiredAllocation(BudgetAllocation $allocation): float
    {
        $totals = BudgetTransaction::query()
            ->where('budget_allocation_id', $allocation->id)
            ->selectRaw('transaction_type, COALESCE(SUM(signed_amount), 0) as total')
            ->groupBy('transaction_type')
            ->pluck('total', 'transaction_type')
            ->map(fn ($value): float => round((float) $value, 2));

        $committed = round(
            ($totals[BudgetLedgerService::TYPE_COMMITMENT] ?? 0.0)
            + ($totals[BudgetLedgerService::TYPE_COMMITMENT_RELEASE] ?? 0.0)
            + ($totals[BudgetLedgerService::TYPE_COMMITMENT_LIQUIDATION] ?? 0.0),
            2
        );
        $spent = round($totals[BudgetLedgerService::TYPE_EXPENDITURE] ?? 0.0, 2);

        return round($committed + $spent, 2);
    }

    protected function allocationTitle(BudgetLine $line): string
    {
        $parts = [
            'Personnel',
            $line->department?->name,
            $line->salaryScale?->code,
            $line->level ? 'Level '.$line->level : null,
        ];

        return collect($parts)->filter()->implode(' - ');
    }
}
