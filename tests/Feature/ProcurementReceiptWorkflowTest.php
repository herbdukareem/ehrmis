<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetAccount;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCommitment;
use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Budget\Models\BudgetTransaction;
use App\Domain\Budget\Services\BudgetLedgerService;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Procurement\Models\ProcurementBid;
use App\Domain\Procurement\Models\ProcurementReceipt;
use App\Domain\Procurement\Models\ProcurementReceiptItem;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\ProcurementSolicitation;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\ProcurementAwardService;
use App\Domain\Procurement\Services\ProcurementReceiptService;
use App\Domain\Procurement\Services\ProcurementRequisitionService;
use App\Domain\Procurement\Services\ProcurementSourcingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcurementReceiptWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_posted_receipt_liquidates_commitment_and_updates_purchase_order_status(): void
    {
        [$purchaseOrder, $allocation, $actor] = $this->purchaseOrderWithCommittedItem(400);
        $item = $purchaseOrder->items()->firstOrFail();
        $service = app(ProcurementReceiptService::class);

        $firstReceipt = $service->createReceipt($purchaseOrder, ['delivery_note_no' => 'DN-001'], $actor);
        $service->addItem($firstReceipt, $item, ['quantity_received' => 2, 'quantity_accepted' => 2], $actor);
        $postedFirst = $service->postReceipt($firstReceipt, $actor);

        $this->assertSame('posted', $postedFirst->status);
        $this->assertSame('accepted', $postedFirst->inspection_status);
        $this->assertSame('partially_received', $item->fresh()->status);
        $this->assertSame('partially_received', $purchaseOrder->fresh()->status);
        $this->assertDatabaseHas('budget_transactions', [
            'budget_allocation_id' => $allocation->id,
            'transaction_type' => BudgetLedgerService::TYPE_COMMITMENT_LIQUIDATION,
            'signed_amount' => -200,
        ]);
        $this->assertDatabaseHas('budget_transactions', [
            'budget_allocation_id' => $allocation->id,
            'transaction_type' => BudgetLedgerService::TYPE_EXPENDITURE,
            'signed_amount' => 200,
        ]);

        $balance = app(BudgetLedgerService::class)->balancesForAllocation($allocation);
        $this->assertEquals(200.0, $balance['committed_amount']);
        $this->assertEquals(200.0, $balance['actual_amount']);
        $this->assertEquals(600.0, $balance['available_amount']);

        $secondReceipt = $service->createReceipt($purchaseOrder->fresh(), ['delivery_note_no' => 'DN-002'], $actor);
        $service->addItem($secondReceipt, $item->fresh(), ['quantity_received' => 2, 'quantity_accepted' => 2], $actor);
        $service->postReceipt($secondReceipt, $actor);

        $this->assertSame('received', $item->fresh()->status);
        $this->assertSame('received', $purchaseOrder->fresh()->status);
        $this->assertSame('liquidated', BudgetCommitment::query()->firstOrFail()->status);
    }

    public function test_receipt_cannot_exceed_remaining_purchase_order_quantity_or_amount(): void
    {
        [$purchaseOrder,, $actor] = $this->purchaseOrderWithCommittedItem(400);
        $item = $purchaseOrder->items()->firstOrFail();
        $service = app(ProcurementReceiptService::class);

        $receipt = $service->createReceipt($purchaseOrder, [], $actor);
        $service->addItem($receipt, $item, ['quantity_received' => 3, 'quantity_accepted' => 3], $actor);
        $service->postReceipt($receipt, $actor);

        $this->expectException(ValidationException::class);

        $nextReceipt = $service->createReceipt($purchaseOrder->fresh(), [], $actor);
        $service->addItem($nextReceipt, $item->fresh(), ['quantity_received' => 2, 'quantity_accepted' => 2], $actor);
    }

    public function test_posting_receipt_is_idempotent(): void
    {
        [$purchaseOrder, $allocation, $actor] = $this->purchaseOrderWithCommittedItem(400);
        $item = $purchaseOrder->items()->firstOrFail();
        $service = app(ProcurementReceiptService::class);
        $receipt = $service->createReceipt($purchaseOrder, [], $actor);
        $service->addItem($receipt, $item, ['quantity_received' => 1, 'quantity_accepted' => 1], $actor);

        $service->postReceipt($receipt, $actor);
        $service->postReceipt($receipt->fresh(), $actor);

        $this->assertSame(1, BudgetTransaction::query()
            ->where('budget_allocation_id', $allocation->id)
            ->where('transaction_type', BudgetLedgerService::TYPE_COMMITMENT_LIQUIDATION)
            ->count());
        $this->assertSame(1, BudgetTransaction::query()
            ->where('budget_allocation_id', $allocation->id)
            ->where('transaction_type', BudgetLedgerService::TYPE_EXPENDITURE)
            ->count());
    }

    public function test_procurement_receipts_are_mda_scoped(): void
    {
        [$ownPo,, $actor] = $this->purchaseOrderWithCommittedItem(400);
        [$otherPo] = $this->purchaseOrderWithCommittedItem(400, 'HMB');
        $service = app(ProcurementReceiptService::class);
        $ownReceipt = $service->createReceipt($ownPo, [], $actor);
        $service->createReceipt($otherPo);

        $this->actingAs($actor);

        $this->assertSame([$ownReceipt->id], ProcurementReceipt::query()->pluck('id')->all());
    }

    /**
     * @return array{0:PurchaseOrder,1:BudgetAllocation,2:User}
     */
    protected function purchaseOrderWithCommittedItem(float $amount, string $mdaCode = 'MOH'): array
    {
        [$mda, $department, $actor] = $this->organization($mdaCode);
        $allocation = $this->fundedAllocation($mda, $department, 1000);
        $requisition = $this->approvedRequisition($mda, $department, $allocation, $amount, $actor);
        $solicitation = ProcurementSolicitation::query()->create([
            'mda_id' => $mda->id,
            'procurement_requisition_id' => $requisition->id,
            'solicitation_no' => 'SRC-'.$mda->code.'-'.str_pad((string) (ProcurementSolicitation::withoutGlobalScopes()->count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'Supply office equipment',
            'procurement_method' => ProcurementSourcingService::METHOD_RFQ,
            'estimated_amount' => $amount,
            'currency' => 'NGN',
            'status' => 'closed',
        ]);
        $vendor = Vendor::query()->create([
            'mda_id' => $mda->id,
            'vendor_no' => 'VEN-'.$mda->code.'-'.str_pad((string) (Vendor::withoutGlobalScopes()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => 'Alpha Supplies Ltd',
            'vendor_type' => 'supplier',
            'status' => 'active',
        ]);
        $bid = ProcurementBid::query()->create([
            'mda_id' => $mda->id,
            'procurement_solicitation_id' => $solicitation->id,
            'vendor_id' => $vendor->id,
            'bid_no' => 'BID-'.str_pad((string) (ProcurementBid::withoutGlobalScopes()->count() + 1), 4, '0', STR_PAD_LEFT),
            'quoted_amount' => $amount,
            'currency' => 'NGN',
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
        $awardService = app(ProcurementAwardService::class);
        $evaluation = $awardService->evaluateBid($bid, ['technical_score' => 45, 'financial_score' => 45], $actor);
        $award = $awardService->approveAward($awardService->recommendAward($evaluation, $actor, 'Recommended.'), $actor);

        return [$awardService->generatePurchaseOrder($award, $actor), $allocation, $actor];
    }

    /**
     * @return array{0:Mda,1:Department,2:User}
     */
    protected function organization(string $code): array
    {
        $mda = Mda::query()->create([
            'code' => $code,
            'name' => $code === 'MOH' ? 'Ministry of Health' : 'Hospital Management Board',
            'status' => 'active',
        ]);
        $department = Department::query()->create([
            'mda_id' => $mda->id,
            'code' => 'ADMIN',
            'name' => 'Administration',
            'status' => 'active',
        ]);
        $actor = User::factory()->mdaUser($mda)->create();

        return [$mda, $department, $actor];
    }

    protected function fundedAllocation(Mda $mda, Department $department, float $amount): BudgetAllocation
    {
        $cycle = BudgetCycle::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'name' => '2026 Annual Budget',
            'status' => 'open',
        ]);
        $account = BudgetAccount::query()->create([
            'mda_id' => $mda->id,
            'code' => 'OVH-'.$mda->code,
            'name' => 'Overhead',
            'account_type' => 'overhead',
            'status' => 'active',
        ]);
        $allocation = BudgetAllocation::query()->create([
            'mda_id' => $mda->id,
            'budget_cycle_id' => $cycle->id,
            'budget_account_id' => $account->id,
            'department_id' => $department->id,
            'title' => 'Overhead allocation',
            'approved_amount' => $amount,
            'status' => 'draft',
        ]);

        return app(BudgetLedgerService::class)->approveAllocation($allocation);
    }

    protected function approvedRequisition(Mda $mda, Department $department, BudgetAllocation $allocation, float $amount, User $actor): ProcurementRequisition
    {
        $service = app(ProcurementRequisitionService::class);
        $requisition = $service->createRequisition([
            'mda_id' => $mda->id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'department_id' => $department->id,
            'title' => 'Office equipment requisition',
        ], $actor);
        $service->addLine($requisition, [
            'budget_allocation_id' => $allocation->id,
            'department_id' => $department->id,
            'item_description' => 'Computers',
            'quantity' => 4,
            'unit' => 'unit',
            'estimated_unit_cost' => $amount / 4,
            'estimated_amount' => $amount,
        ], $actor);
        $service->submit($requisition, $actor);
        $service->reviewBudgetAvailability($requisition, $actor);

        return $service->approve($requisition, $actor);
    }
}
