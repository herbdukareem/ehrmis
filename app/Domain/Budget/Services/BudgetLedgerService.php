<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Models\BudgetAdjustment;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCommitment;
use App\Domain\Budget\Models\BudgetRelease;
use App\Domain\Budget\Models\BudgetTransaction;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BudgetLedgerService
{
    public const TYPE_ALLOCATION = 'allocation';
    public const TYPE_RELEASE = 'release';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_COMMITMENT = 'commitment';
    public const TYPE_COMMITMENT_RELEASE = 'commitment_release';
    public const TYPE_COMMITMENT_LIQUIDATION = 'commitment_liquidation';
    public const TYPE_EXPENDITURE = 'expenditure';

    public function __construct(private AuditLogService $auditLogService) {}

    public function approveAllocation(BudgetAllocation $allocation, ?User $actor = null): BudgetAllocation
    {
        return DB::transaction(function () use ($allocation, $actor): BudgetAllocation {
            $allocation = BudgetAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
            $before = $allocation->toArray();

            $allocation->forceFill([
                'status' => 'approved',
                'approved_by' => $actor?->id ?? $allocation->approved_by,
                'approved_at' => $allocation->approved_at ?? now(),
            ])->save();

            $this->postSourceTransaction(
                self::TYPE_ALLOCATION,
                $allocation,
                (float) $allocation->approved_amount,
                (float) $allocation->approved_amount,
                'Approved budget allocation',
                $actor,
            );

            $this->auditLogService->logUpdated($allocation, $before, ['source' => 'budget_ledger.allocation_approved']);

            return $allocation->fresh(['cycle', 'account', 'transactions']);
        });
    }

    public function postRelease(BudgetRelease $release, ?User $actor = null): BudgetRelease
    {
        return DB::transaction(function () use ($release, $actor): BudgetRelease {
            $release = BudgetRelease::query()->with('allocation')->lockForUpdate()->findOrFail($release->id);
            $this->assertPositive((float) $release->amount, 'Release amount must be greater than zero.');

            if ($release->status === 'posted') {
                return $release->fresh();
            }

            $before = $release->toArray();
            $balance = $this->balancesForAllocation($release->allocation);

            if ($balance['unreleased_amount'] < (float) $release->amount) {
                $this->fail('This release exceeds the unreleased allocation balance.');
            }

            $release->forceFill([
                'status' => 'posted',
                'released_by' => $actor?->id ?? $release->released_by,
                'posted_at' => now(),
            ])->save();

            $this->postSourceTransaction(
                self::TYPE_RELEASE,
                $release,
                (float) $release->amount,
                (float) $release->amount,
                'Budget release posted',
                $actor,
            );

            $this->auditLogService->logUpdated($release, $before, ['source' => 'budget_ledger.release_posted']);

            return $release->fresh();
        });
    }

    public function approveAdjustment(BudgetAdjustment $adjustment, ?User $actor = null): BudgetAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor): BudgetAdjustment {
            $adjustment = BudgetAdjustment::query()->with('allocation')->lockForUpdate()->findOrFail($adjustment->id);
            $this->assertPositive((float) $adjustment->amount, 'Adjustment amount must be greater than zero.');

            if ($adjustment->status === 'approved') {
                return $adjustment->fresh();
            }

            $before = $adjustment->toArray();
            $signedAmount = $adjustment->signedAmount();

            if ($signedAmount < 0) {
                $balance = $this->balancesForAllocation($adjustment->allocation);
                if ($balance['available_amount'] < abs($signedAmount)) {
                    $this->fail('This adjustment would reduce the allocation below committed or spent funds.');
                }
            }

            $adjustment->forceFill([
                'status' => 'approved',
                'approved_by' => $actor?->id ?? $adjustment->approved_by,
                'approved_at' => now(),
            ])->save();

            $this->postSourceTransaction(
                self::TYPE_ADJUSTMENT,
                $adjustment,
                (float) $adjustment->amount,
                $signedAmount,
                'Budget adjustment approved',
                $actor,
            );

            $this->auditLogService->logUpdated($adjustment, $before, ['source' => 'budget_ledger.adjustment_approved']);

            return $adjustment->fresh();
        });
    }

    public function postCommitment(BudgetCommitment $commitment, ?User $actor = null): BudgetCommitment
    {
        return DB::transaction(function () use ($commitment, $actor): BudgetCommitment {
            $commitment = BudgetCommitment::query()->with('allocation')->lockForUpdate()->findOrFail($commitment->id);
            $this->assertPositive((float) $commitment->amount, 'Commitment amount must be greater than zero.');

            if ($commitment->committed_at !== null) {
                return $commitment->fresh();
            }

            $before = $commitment->toArray();
            $balance = $this->balancesForAllocation($commitment->allocation);

            if ($balance['available_amount'] < (float) $commitment->amount) {
                $this->fail('This commitment exceeds the available allocation balance.');
            }

            $commitment->forceFill([
                'status' => 'active',
                'committed_by' => $actor?->id ?? $commitment->committed_by,
                'committed_at' => now(),
            ])->save();

            $this->postSourceTransaction(
                self::TYPE_COMMITMENT,
                $commitment,
                (float) $commitment->amount,
                (float) $commitment->amount,
                'Budget commitment posted',
                $actor,
            );

            $this->auditLogService->logUpdated($commitment, $before, ['source' => 'budget_ledger.commitment_posted']);

            return $commitment->fresh();
        });
    }

    public function releaseCommitment(BudgetCommitment $commitment, float $amount, ?User $actor = null, ?string $description = null): BudgetCommitment
    {
        return DB::transaction(function () use ($commitment, $amount, $actor, $description): BudgetCommitment {
            $commitment = BudgetCommitment::query()->with('allocation')->lockForUpdate()->findOrFail($commitment->id);
            $this->assertPositive($amount, 'Released commitment amount must be greater than zero.');

            if ($this->outstandingCommitment($commitment) < $amount) {
                $this->fail('This amount exceeds the outstanding commitment balance.');
            }

            $before = $commitment->toArray();

            $this->postTransaction(
                $commitment->allocation,
                self::TYPE_COMMITMENT_RELEASE,
                $amount,
                -1 * $amount,
                $description ?? 'Budget commitment released',
                $actor,
                $commitment,
            );

            $outstanding = $this->outstandingCommitment($commitment);
            $commitment->forceFill(['status' => $outstanding <= 0.0 ? 'released' : 'partially_released'])->save();

            $this->auditLogService->logUpdated($commitment, $before, ['source' => 'budget_ledger.commitment_released']);

            return $commitment->fresh();
        });
    }

    public function liquidateCommitment(BudgetCommitment $commitment, float $amount, ?User $actor = null, ?string $description = null): BudgetCommitment
    {
        return DB::transaction(function () use ($commitment, $amount, $actor, $description): BudgetCommitment {
            $commitment = BudgetCommitment::query()->with('allocation')->lockForUpdate()->findOrFail($commitment->id);
            $this->assertPositive($amount, 'Liquidation amount must be greater than zero.');

            if ($this->outstandingCommitment($commitment) < $amount) {
                $this->fail('This amount exceeds the outstanding commitment balance.');
            }

            $before = $commitment->toArray();

            $this->postTransaction(
                $commitment->allocation,
                self::TYPE_COMMITMENT_LIQUIDATION,
                $amount,
                -1 * $amount,
                $description ?? 'Budget commitment liquidated',
                $actor,
                $commitment,
            );

            $this->postTransaction(
                $commitment->allocation,
                self::TYPE_EXPENDITURE,
                $amount,
                $amount,
                $description ?? 'Budget expenditure posted',
                $actor,
                $commitment,
            );

            $outstanding = $this->outstandingCommitment($commitment);
            $commitment->forceFill(['status' => $outstanding <= 0.0 ? 'liquidated' : 'partially_liquidated'])->save();

            $this->auditLogService->logUpdated($commitment, $before, ['source' => 'budget_ledger.commitment_liquidated']);

            return $commitment->fresh();
        });
    }

    public function recordExpenditure(BudgetAllocation $allocation, float $amount, ?User $actor = null, ?string $description = null): BudgetTransaction
    {
        return DB::transaction(function () use ($allocation, $amount, $actor, $description): BudgetTransaction {
            $allocation = BudgetAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
            $this->assertPositive($amount, 'Expenditure amount must be greater than zero.');

            $balance = $this->balancesForAllocation($allocation);
            if ($balance['available_amount'] < $amount) {
                $this->fail('This expenditure exceeds the available allocation balance.');
            }

            return $this->postTransaction(
                $allocation,
                self::TYPE_EXPENDITURE,
                $amount,
                $amount,
                $description ?? 'Budget expenditure posted',
                $actor,
            );
        });
    }

    /**
     * @return array<string, float>
     */
    public function balancesForAllocation(BudgetAllocation $allocation): array
    {
        $totals = BudgetTransaction::query()
            ->where('budget_allocation_id', $allocation->id)
            ->selectRaw('transaction_type, COALESCE(SUM(signed_amount), 0) as total')
            ->groupBy('transaction_type')
            ->pluck('total', 'transaction_type')
            ->map(fn ($value): float => round((float) $value, 2));

        $allocated = round(($totals[self::TYPE_ALLOCATION] ?? 0.0) + ($totals[self::TYPE_ADJUSTMENT] ?? 0.0), 2);
        $released = round($totals[self::TYPE_RELEASE] ?? 0.0, 2);
        $committed = round(
            ($totals[self::TYPE_COMMITMENT] ?? 0.0)
            + ($totals[self::TYPE_COMMITMENT_RELEASE] ?? 0.0)
            + ($totals[self::TYPE_COMMITMENT_LIQUIDATION] ?? 0.0),
            2
        );
        $spent = round($totals[self::TYPE_EXPENDITURE] ?? 0.0, 2);

        return [
            'allocated_amount' => $allocated,
            'released_amount' => $released,
            'committed_amount' => $committed,
            'actual_amount' => $spent,
            'unreleased_amount' => round($allocated - $released, 2),
            'available_amount' => round($allocated - $committed - $spent, 2),
            'released_available_amount' => round($released - $committed - $spent, 2),
        ];
    }

    public function outstandingCommitment(BudgetCommitment $commitment): float
    {
        $total = BudgetTransaction::query()
            ->where('source_type', $commitment::class)
            ->where('source_id', $commitment->id)
            ->whereIn('transaction_type', [
                self::TYPE_COMMITMENT,
                self::TYPE_COMMITMENT_RELEASE,
                self::TYPE_COMMITMENT_LIQUIDATION,
            ])
            ->sum('signed_amount');

        return round((float) $total, 2);
    }

    protected function postSourceTransaction(
        string $type,
        Model $source,
        float $amount,
        float $signedAmount,
        string $description,
        ?User $actor,
    ): BudgetTransaction {
        $existing = BudgetTransaction::query()
            ->where('transaction_type', $type)
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->postTransaction($source->allocation ?? $source, $type, $amount, $signedAmount, $description, $actor, $source);
    }

    protected function postTransaction(
        BudgetAllocation $allocation,
        string $type,
        float $amount,
        float $signedAmount,
        string $description,
        ?User $actor,
        ?Model $source = null,
    ): BudgetTransaction {
        $transaction = BudgetTransaction::query()->create([
            'mda_id' => $allocation->mda_id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'budget_allocation_id' => $allocation->id,
            'budget_account_id' => $allocation->budget_account_id,
            'department_id' => $allocation->department_id,
            'transaction_type' => $type,
            'amount' => round(abs($amount), 2),
            'signed_amount' => round($signedAmount, 2),
            'transaction_date' => now()->toDateString(),
            'source_type' => $source ? $source::class : null,
            'source_id' => $source?->getKey(),
            'description' => $description,
            'recorded_by' => $actor?->id,
        ]);

        $this->auditLogService->logCreated($transaction, [
            'source' => 'budget_ledger.transaction_posted',
            'transaction_type' => $type,
            'mda_id' => $allocation->mda_id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'budget_allocation_id' => $allocation->id,
        ]);

        return $transaction;
    }

    protected function assertPositive(float $amount, string $message): void
    {
        if ($amount <= 0) {
            $this->fail($message);
        }
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['budget' => $message]);
    }
}
