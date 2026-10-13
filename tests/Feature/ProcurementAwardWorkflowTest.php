<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Procurement\Models\ProcurementAward;
use App\Domain\Procurement\Models\ProcurementBid;
use App\Domain\Procurement\Models\ProcurementEvaluation;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\ProcurementRequisitionLine;
use App\Domain\Procurement\Models\ProcurementSolicitation;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\ProcurementAwardService;
use App\Domain\Procurement\Services\ProcurementSourcingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcurementAwardWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluation_recommendation_award_approval_and_po_generation_flow(): void
    {
        [$mda, $department, $actor] = $this->organization();
        [$solicitation, $alphaBid] = $this->solicitationWithBid($mda, $department, 'Alpha Supplies Ltd', 950000);
        $service = app(ProcurementAwardService::class);

        $evaluation = $service->evaluateBid($alphaBid, [
            'technical_score' => 42.5,
            'financial_score' => 47.5,
            'rank' => 1,
            'criteria_scores' => ['delivery' => 20, 'price' => 47.5],
        ], $actor);
        $award = $service->recommendAward($evaluation, $actor, 'Best evaluated responsive bid.');
        $approvedAward = $service->approveAward($award, $actor);
        $purchaseOrder = $service->generatePurchaseOrder($approvedAward, $actor, [
            'expected_delivery_date' => '2026-11-30',
            'terms' => 'Deliver to MDA headquarters.',
        ]);

        $this->assertSame('evaluated', $evaluation->status);
        $this->assertEquals(90.0, (float) $evaluation->total_score);
        $this->assertSame('approved', $approvedAward->status);
        $this->assertSame('awarded', $solicitation->fresh()->status);
        $this->assertStringStartsWith('AWD-', $approvedAward->award_no);
        $this->assertStringStartsWith('PO-', $purchaseOrder->po_no);
        $this->assertSame('issued', $purchaseOrder->status);
        $this->assertSame($approvedAward->id, $purchaseOrder->procurement_award_id);
        $this->assertSame($alphaBid->vendor_id, $purchaseOrder->vendor_id);
        $this->assertEquals(950000.0, (float) $purchaseOrder->total_amount);
        $this->assertSame(2, $purchaseOrder->items()->count());
        $this->assertEquals(950000.0, (float) PurchaseOrderItem::query()->where('purchase_order_id', $purchaseOrder->id)->sum('total_amount'));
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'procurement.award_approved']);
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'procurement.purchase_order_generated']);
    }

    public function test_recommending_another_bid_switches_recommendation_and_updates_award(): void
    {
        [$mda, $department, $actor] = $this->organization();
        [$solicitation, $alphaBid] = $this->solicitationWithBid($mda, $department, 'Alpha Supplies Ltd', 950000);
        $betaBid = $this->bid($solicitation, $this->vendor($mda, 'Beta Integrated Services'), 900000);
        $service = app(ProcurementAwardService::class);

        $alphaEvaluation = $service->evaluateBid($alphaBid, ['technical_score' => 45, 'financial_score' => 40], $actor);
        $service->recommendAward($alphaEvaluation, $actor, 'Initial recommendation.');
        $betaEvaluation = $service->evaluateBid($betaBid, ['technical_score' => 44, 'financial_score' => 48], $actor);
        $award = $service->recommendAward($betaEvaluation, $actor, 'Lower responsive quote.');

        $this->assertFalse((bool) $alphaEvaluation->fresh()->recommended);
        $this->assertTrue((bool) $betaEvaluation->fresh()->recommended);
        $this->assertSame('evaluated', $alphaBid->fresh()->status);
        $this->assertSame('recommended', $betaBid->fresh()->status);
        $this->assertSame($betaBid->id, $award->procurement_bid_id);
        $this->assertSame($betaBid->vendor_id, $award->vendor_id);
        $this->assertEquals(900000.0, (float) $award->award_amount);
        $this->assertSame(1, ProcurementAward::query()->where('procurement_solicitation_id', $solicitation->id)->count());
    }

    public function test_po_generation_requires_approved_award(): void
    {
        [$mda, $department, $actor] = $this->organization();
        [, $bid] = $this->solicitationWithBid($mda, $department, 'Alpha Supplies Ltd', 950000);
        $service = app(ProcurementAwardService::class);
        $evaluation = $service->evaluateBid($bid, ['technical_score' => 40, 'financial_score' => 40], $actor);
        $award = $service->recommendAward($evaluation, $actor, 'Recommended.');

        $this->expectException(ValidationException::class);

        $service->generatePurchaseOrder($award, $actor);
    }

    public function test_awards_and_purchase_orders_are_mda_scoped(): void
    {
        [$mda, $department, $actor] = $this->organization();
        [$otherMda, $otherDepartment] = $this->organization('HMB');
        $ownAward = $this->approvedAward($mda, $department, $actor);
        $ownPo = app(ProcurementAwardService::class)->generatePurchaseOrder($ownAward, $actor);
        $otherAward = $this->approvedAward($otherMda, $otherDepartment);
        app(ProcurementAwardService::class)->generatePurchaseOrder($otherAward);

        $this->actingAs($actor);

        $this->assertSame([$ownAward->id], ProcurementAward::query()->pluck('id')->all());
        $this->assertSame([$ownPo->id], PurchaseOrder::query()->pluck('id')->all());
    }

    /**
     * @return array{0:Mda,1:Department,2:User}
     */
    protected function organization(string $code = 'MOH'): array
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

    /**
     * @return array{0:ProcurementSolicitation,1:ProcurementBid}
     */
    protected function solicitationWithBid(Mda $mda, Department $department, string $vendorName, float $amount): array
    {
        $requisition = $this->approvedRequisition($mda, $department);
        $solicitation = ProcurementSolicitation::query()->create([
            'mda_id' => $mda->id,
            'procurement_requisition_id' => $requisition->id,
            'solicitation_no' => 'SRC-'.$mda->code.'-'.str_pad((string) (ProcurementSolicitation::withoutGlobalScopes()->count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'Supply office equipment',
            'procurement_method' => ProcurementSourcingService::METHOD_RFQ,
            'estimated_amount' => 1000000,
            'currency' => 'NGN',
            'status' => 'closed',
        ]);
        $vendor = $this->vendor($mda, $vendorName);

        return [$solicitation, $this->bid($solicitation, $vendor, $amount)];
    }

    protected function approvedRequisition(Mda $mda, Department $department): ProcurementRequisition
    {
        $requisition = ProcurementRequisition::query()->create([
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'requisition_no' => 'REQ-'.$mda->code.'-'.str_pad((string) (ProcurementRequisition::withoutGlobalScopes()->count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'Office equipment requisition',
            'total_amount' => 1000000,
            'status' => 'approved',
            'budget_review_status' => 'available',
            'approved_at' => now(),
        ]);

        ProcurementRequisitionLine::query()->create([
            'mda_id' => $mda->id,
            'procurement_requisition_id' => $requisition->id,
            'department_id' => $department->id,
            'item_description' => 'Computers',
            'quantity' => 5,
            'unit' => 'unit',
            'estimated_unit_cost' => 120000,
            'estimated_amount' => 600000,
            'status' => 'committed',
            'budget_check_status' => 'available',
        ]);
        ProcurementRequisitionLine::query()->create([
            'mda_id' => $mda->id,
            'procurement_requisition_id' => $requisition->id,
            'department_id' => $department->id,
            'item_description' => 'Printers',
            'quantity' => 4,
            'unit' => 'unit',
            'estimated_unit_cost' => 100000,
            'estimated_amount' => 400000,
            'status' => 'committed',
            'budget_check_status' => 'available',
        ]);

        return $requisition->fresh('lines');
    }

    protected function vendor(Mda $mda, string $name): Vendor
    {
        return Vendor::query()->create([
            'mda_id' => $mda->id,
            'vendor_no' => 'VEN-'.$mda->code.'-'.str_pad((string) (Vendor::withoutGlobalScopes()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'vendor_type' => 'supplier',
            'status' => 'active',
        ]);
    }

    protected function bid(ProcurementSolicitation $solicitation, Vendor $vendor, float $amount): ProcurementBid
    {
        return ProcurementBid::query()->create([
            'mda_id' => $solicitation->mda_id,
            'procurement_solicitation_id' => $solicitation->id,
            'vendor_id' => $vendor->id,
            'bid_no' => 'BID-'.str_pad((string) (ProcurementBid::withoutGlobalScopes()->count() + 1), 4, '0', STR_PAD_LEFT),
            'quoted_amount' => $amount,
            'currency' => 'NGN',
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
    }

    protected function approvedAward(Mda $mda, Department $department, ?User $actor = null): ProcurementAward
    {
        [, $bid] = $this->solicitationWithBid($mda, $department, 'Alpha Supplies Ltd', 950000);
        $service = app(ProcurementAwardService::class);
        $evaluation = $service->evaluateBid($bid, ['technical_score' => 40, 'financial_score' => 45], $actor);
        $award = $service->recommendAward($evaluation, $actor, 'Recommended.');

        return $service->approveAward($award, $actor);
    }
}
