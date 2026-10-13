<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetAccount;
use App\Domain\Budget\Models\BudgetAdjustment;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCommitment;
use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Budget\Models\BudgetRelease;
use App\Domain\Budget\Models\BudgetTransaction;
use App\Domain\Budget\Services\BudgetLedgerService;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BudgetFoundationLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_allocations_releases_commitments_and_actuals_are_tracked_in_the_ledger(): void
    {
        [$actor, $allocation] = $this->allocation(amount: 1000);
        $ledger = app(BudgetLedgerService::class);

        $ledger->approveAllocation($allocation, $actor);

        $release = BudgetRelease::query()->create([
            'mda_id' => $allocation->mda_id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'budget_allocation_id' => $allocation->id,
            'amount' => 700,
            'release_date' => now()->toDateString(),
        ]);

        $ledger->postRelease($release, $actor);

        $commitment = BudgetCommitment::query()->create([
            'mda_id' => $allocation->mda_id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'budget_allocation_id' => $allocation->id,
            'commitment_no' => 'COM-2026-001',
            'description' => 'Procure office equipment',
            'amount' => 400,
        ]);

        $ledger->postCommitment($commitment, $actor);
        $ledger->liquidateCommitment($commitment, 250, $actor);
        $ledger->releaseCommitment($commitment, 150, $actor);

        $this->assertSame([
            'allocated_amount' => 1000.0,
            'released_amount' => 700.0,
            'committed_amount' => 0.0,
            'actual_amount' => 250.0,
            'unreleased_amount' => 300.0,
            'available_amount' => 750.0,
            'released_available_amount' => 450.0,
        ], $ledger->balancesForAllocation($allocation));

        $this->assertDatabaseHas('budget_transactions', [
            'budget_allocation_id' => $allocation->id,
            'transaction_type' => BudgetLedgerService::TYPE_ALLOCATION,
            'signed_amount' => 1000,
        ]);
        $this->assertDatabaseHas('budget_transactions', [
            'budget_allocation_id' => $allocation->id,
            'transaction_type' => BudgetLedgerService::TYPE_COMMITMENT_LIQUIDATION,
            'signed_amount' => -250,
        ]);
        $this->assertDatabaseHas('budget_transactions', [
            'budget_allocation_id' => $allocation->id,
            'transaction_type' => BudgetLedgerService::TYPE_EXPENDITURE,
            'signed_amount' => 250,
        ]);

        $this->assertSame('released', $commitment->fresh()->status);
        $this->assertSame(6, BudgetTransaction::query()->where('budget_allocation_id', $allocation->id)->count());
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'created', 'auditable_type' => BudgetTransaction::class]);
    }

    public function test_adjustments_move_allocation_balance_without_hiding_commitments_or_spend(): void
    {
        [$actor, $allocation] = $this->allocation(amount: 500);
        $ledger = app(BudgetLedgerService::class);

        $ledger->approveAllocation($allocation, $actor);
        $ledger->recordExpenditure($allocation, 100, $actor);

        $increase = BudgetAdjustment::query()->create([
            'mda_id' => $allocation->mda_id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'budget_allocation_id' => $allocation->id,
            'adjustment_type' => 'increase',
            'amount' => 200,
            'reason' => 'Supplementary allocation',
        ]);
        $decrease = BudgetAdjustment::query()->create([
            'mda_id' => $allocation->mda_id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'budget_allocation_id' => $allocation->id,
            'adjustment_type' => 'decrease',
            'amount' => 50,
            'reason' => 'Virement out',
        ]);

        $ledger->approveAdjustment($increase, $actor);
        $ledger->approveAdjustment($decrease, $actor);

        $this->assertSame([
            'allocated_amount' => 650.0,
            'released_amount' => 0.0,
            'committed_amount' => 0.0,
            'actual_amount' => 100.0,
            'unreleased_amount' => 650.0,
            'available_amount' => 550.0,
            'released_available_amount' => -100.0,
        ], $ledger->balancesForAllocation($allocation));
    }

    public function test_ledger_rejects_transactions_that_exceed_available_budget(): void
    {
        [$actor, $allocation] = $this->allocation(amount: 300);
        $ledger = app(BudgetLedgerService::class);

        $ledger->approveAllocation($allocation, $actor);

        $commitment = BudgetCommitment::query()->create([
            'mda_id' => $allocation->mda_id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'budget_allocation_id' => $allocation->id,
            'description' => 'Oversized request',
            'amount' => 350,
        ]);

        try {
            $ledger->postCommitment($commitment, $actor);
            $this->fail('Expected the ledger to reject an oversized commitment.');
        } catch (ValidationException $exception) {
            $this->assertSame('This commitment exceeds the available allocation balance.', $exception->errors()['budget'][0]);
        }

        $release = BudgetRelease::query()->create([
            'mda_id' => $allocation->mda_id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'budget_allocation_id' => $allocation->id,
            'amount' => 400,
            'release_date' => now()->toDateString(),
        ]);

        try {
            $ledger->postRelease($release, $actor);
            $this->fail('Expected the ledger to reject an oversized release.');
        } catch (ValidationException $exception) {
            $this->assertSame('This release exceeds the unreleased allocation balance.', $exception->errors()['budget'][0]);
        }
    }

    public function test_budget_foundation_records_are_mda_scoped(): void
    {
        [$actor, $allocation] = $this->allocation(amount: 100);
        [, $otherAllocation] = $this->allocation(amount: 100);

        $this->actingAs($actor);

        $this->assertSame([$allocation->budget_cycle_id], BudgetCycle::query()->pluck('id')->all());
        $this->assertSame([$allocation->budget_account_id], BudgetAccount::query()->pluck('id')->all());
        $this->assertSame([$allocation->id], BudgetAllocation::query()->pluck('id')->all());

        $this->assertNotSame($allocation->mda_id, $otherAllocation->mda_id);
    }

    /**
     * @return array{0: User, 1: BudgetAllocation}
     */
    protected function allocation(float $amount): array
    {
        $mda = Mda::factory()->create();
        $actor = User::factory()->mdaUser($mda)->create();
        $cycle = BudgetCycle::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'name' => '2026 Approved Budget',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);
        $account = BudgetAccount::query()->create([
            'mda_id' => $mda->id,
            'code' => '221001',
            'name' => 'General Services',
            'account_type' => 'recurrent',
        ]);
        $allocation = BudgetAllocation::query()->create([
            'mda_id' => $mda->id,
            'budget_cycle_id' => $cycle->id,
            'budget_account_id' => $account->id,
            'title' => 'General operations',
            'approved_amount' => $amount,
            'status' => 'draft',
            'allocated_by' => $actor->id,
        ]);

        return [$actor, $allocation];
    }
}
