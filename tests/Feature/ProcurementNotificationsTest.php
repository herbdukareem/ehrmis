<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetAccount;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCommitment;
use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Budget\Services\BudgetLedgerService;
use App\Domain\Module\Models\MdaModule;
use App\Domain\Module\Models\Module;
use App\Domain\Organization\Models\Mda;
use App\Domain\Procurement\Models\ProcurementAlertRead;
use App\Domain\Procurement\Models\ProcurementAward;
use App\Domain\Procurement\Models\ProcurementBid;
use App\Domain\Procurement\Models\ProcurementReceipt;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\ProcurementSolicitation;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Procurement\Models\SupplierPayment;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\ProcurementRequisitionService;
use App\Domain\Procurement\Services\ProcurementWorkflowService;
use App\Domain\Procurement\Services\SupplierPayablesService;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcurementNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Mda $mda;

    private Mda $otherMda;

    private User $officer;

    private User $maker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'UTC'));
        $this->mda = Mda::factory()->create();
        $this->otherMda = Mda::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->officer = User::factory()->mdaUser($this->mda)->create();
        $this->maker = User::factory()->mdaUser($this->mda)->create();
        $this->officer->givePermissionTo(['view-procurement', 'view-budget-cycles', 'view-supplier-payables', 'approve-requisitions', 'approve-awards',
            'approve-supplier-invoices', 'approve-supplier-payments', 'create-requisitions', 'evaluate-bids', 'manage-supplier-invoices', 'record-supplier-payments']);
        $this->actingAs($this->officer);
    }

    public function test_pending_approvals_require_read_and_action_permissions_and_resolve_with_status(): void
    {
        $fixture = $this->fixture();
        $rows = $this->getJson($this->url('?category=approvals'))->assertOk()->assertJsonCount(4, 'data.rows')->json('data.rows');
        $this->assertEqualsCanonicalizing(['requisitions', 'awards', 'supplier-invoices', 'supplier-payments'], array_column($rows, 'area'));
        $awardKey = collect($rows)->firstWhere('area', 'awards')['id'];
        $this->officer->revokePermissionTo('approve-awards');
        $this->postJson($this->url('/read'), ['keys' => [$awardKey], 'read' => true])->assertNotFound();
        $this->getJson($this->url('?category=approvals'))->assertOk()->assertJsonCount(3, 'data.rows');
        $this->officer->revokePermissionTo('view-supplier-payables');
        $this->getJson($this->url('?category=approvals'))->assertOk()->assertJsonCount(1, 'data.rows');
        $fixture['request']->update(['status' => 'approved']);
        $this->getJson($this->url('?category=approvals'))->assertOk()->assertJsonCount(0, 'data.rows');
        $fixture['request']->update(['status' => 'budget_reviewed']);
        $this->getJson($this->url('?category=approvals'))->assertOk()->assertJsonCount(1, 'data.rows');
    }

    public function test_payment_makers_are_not_notified_to_approve_their_own_payment(): void
    {
        $fixture = $this->fixture();
        $fixture['payment']->update(['prepared_by' => $this->officer->id]);
        $rows = $this->getJson($this->url('?category=approvals'))->assertOk()->assertJsonCount(3, 'data.rows')->json('data.rows');
        $this->assertNotContains('supplier-payments', array_column($rows, 'area'));
    }

    public function test_returns_and_rejections_include_audited_reasons_and_new_returns_are_unread(): void
    {
        $fixture = $this->fixture();
        $workflow = app(ProcurementWorkflowService::class);
        app(AuditLogService::class)->logUpdated($fixture['request'], [], ['source' => 'procurement_requisition.submitted']);
        $pending = collect($this->getJson($this->url('?category=approvals'))->assertOk()->json('data.rows'))->firstWhere('area', 'requisitions');
        $this->postJson($this->url('/read'), ['keys' => [$pending['id']], 'read' => true])->assertOk();
        $workflow->requisition($fixture['request'], 'return', ['reason' => 'Correct the quantities'], $this->officer);
        app(SupplierPayablesService::class)->invoiceAction($fixture['invoice'], 'return', ['reason' => 'Attach a corrected invoice'], $this->officer);
        $workflow->award($fixture['award'], ['reason' => 'Re-evaluate the bid'], $this->officer);
        app(SupplierPayablesService::class)->paymentAction($fixture['payment'], 'reject', ['reason' => 'Incorrect payment details'], $this->officer);
        $response = $this->getJson($this->url('?category=corrections'))->assertOk()->assertJsonCount(4, 'data.rows');
        $rows = collect($response->json('data.rows'));
        $this->assertContains('Correct the quantities', $rows->pluck('reason')->all());
        $this->assertContains('Attach a corrected invoice', $rows->pluck('reason')->all());
        $this->assertContains('Re-evaluate the bid', $rows->pluck('reason')->all());
        $this->assertContains('Incorrect payment details', $rows->pluck('reason')->all());
        $returned = $rows->firstWhere('area', 'requisitions');
        $this->postJson($this->url('/read'), ['mda_id' => $this->mda->id, 'keys' => [$returned['id']], 'read' => true])->assertOk();
        app(ProcurementRequisitionService::class)->submit($fixture['request'], $this->officer);
        $resubmitted = collect($this->getJson($this->url('?category=approvals'))->assertOk()->json('data.rows'))->firstWhere('area', 'requisitions');
        $this->assertNotSame($pending['id'], $resubmitted['id']);
        $this->assertTrue($resubmitted['unread']);
        $workflow->requisition($fixture['request'], 'return', ['reason' => 'The corrected total is still wrong'], $this->officer);
        $new = collect($this->getJson($this->url('?category=corrections'))->assertOk()->json('data.rows'))->firstWhere('area', 'requisitions');
        $this->assertNotSame($returned['id'], $new['id']);
        $this->assertTrue($new['unread']);
        $this->assertSame('The corrected total is still wrong', $new['reason']);
        $this->maker->givePermissionTo('view-procurement');
        $this->actingAs($this->maker)->getJson($this->url('?category=corrections'))->assertOk()->assertJsonCount(0, 'data.rows');
        $this->actingAs($this->officer)->postJson($this->url('/read-all'), ['category' => 'corrections'])->assertOk();
        $this->travel(3)->days();
        $this->getJson($this->url('?category=corrections&unread=1'))->assertOk()->assertJsonCount(2, 'data.rows');
    }

    public function test_read_state_is_private_idempotent_filtered_and_repeats_only_unresolved_alerts(): void
    {
        $fixture = $this->fixture();
        $key = collect($this->getJson($this->url('?category=approvals'))->assertOk()->json('data.rows'))->firstWhere('area', 'awards')['id'];
        foreach ([true, true] as $read) {
            $this->postJson($this->url('/read'), ['mda_id' => $this->mda->id, 'keys' => [$key], 'read' => $read])->assertOk();
        }
        $this->assertSame(1, ProcurementAlertRead::query()->count());
        $this->getJson($this->url('?category=approvals&unread=1'))->assertOk()->assertJsonCount(3, 'data.rows');
        $this->maker->givePermissionTo($this->officer->getPermissionNames());
        $this->actingAs($this->maker)->getJson($this->url('?category=approvals&unread=1'))->assertOk()->assertJsonCount(3, 'data.rows');
        $this->assertDatabaseMissing('procurement_alert_reads', ['user_id' => $this->maker->id]);
        $this->actingAs($this->officer)->postJson($this->url('/read-all'), ['mda_id' => $this->mda->id, 'category' => 'approvals'])->assertOk()->assertJsonPath('data.updated', 3);
        $this->getJson($this->url('?category=approvals&unread=1'))->assertOk()->assertJsonCount(0, 'data.rows');
        $this->getJson($this->url('?category=deliveries&unread=1'))->assertOk()->assertJsonCount(1, 'data.rows');
        $this->travel(3)->days();
        $this->getJson($this->url('?category=approvals&unread=1'))->assertOk()->assertJsonCount(4, 'data.rows')->assertJsonPath('data.rows.0.is_reminder', true);
        $fixture['request']->update(['status' => 'approved']);
        $this->getJson($this->url('?category=approvals'))->assertOk()->assertJsonCount(3, 'data.rows');
        $this->postJson($this->url('/read'), ['keys' => [$key], 'read' => false])->assertOk();
    }

    public function test_due_dates_use_lagos_and_partial_payments_leave_only_real_outstanding_alerts(): void
    {
        $fixture = $this->fixture();
        $fixture['invoice']->update(['status' => 'approved', 'approved_at' => now(), 'due_date' => '2026-10-19']);
        $this->getJson($this->url('?category=invoices'))->assertOk()->assertJsonCount(0, 'data.rows');
        $this->travelTo(Carbon::parse('2026-10-19 23:10:00', 'UTC'));
        $fixture['payment']->update(['status' => 'paid', 'amount' => '80.00', 'paid_date' => '2026-10-20']);
        $this->getJson($this->url('?category=invoices'))->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.amount', '120.00');
        $fixture['payment']->update(['amount' => '200.00']);
        $this->getJson($this->url('?category=invoices'))->assertOk()->assertJsonCount(0, 'data.rows');
        $fixture['invoice']->update(['status' => 'cancelled']);
        $this->getJson($this->url('?category=invoices'))->assertOk()->assertJsonCount(0, 'data.rows');
    }

    public function test_delivery_alerts_use_posted_accepted_quantities_and_stop_when_complete(): void
    {
        $fixture = $this->fixture();
        $this->getJson($this->url('?category=deliveries'))->assertOk()->assertJsonPath('data.rows.0.incomplete_lines', 1)->assertJsonPath('data.rows.0.type', 'late-delivery');
        $receipt = ProcurementReceipt::query()->create(['mda_id' => $this->mda->id, 'purchase_order_id' => $fixture['order']->id, 'vendor_id' => $fixture['vendor']->id, 'receipt_no' => 'GRN-TEST', 'status' => 'draft']);
        $receipt->items()->create(['mda_id' => $this->mda->id, 'purchase_order_item_id' => $fixture['item']->id, 'item_description' => 'Equipment', 'quantity_received' => 4, 'quantity_accepted' => 4, 'unit_price' => 100, 'accepted_amount' => 400]);
        $this->getJson($this->url('?category=deliveries'))->assertOk()->assertJsonCount(1, 'data.rows');
        $receipt->update(['status' => 'posted', 'posted_at' => now()]);
        $this->getJson($this->url('?category=deliveries'))->assertOk()->assertJsonCount(0, 'data.rows');
        $receipt->update(['status' => 'draft']);
        $fixture['order']->update(['status' => 'cancelled']);
        $this->getJson($this->url('?category=deliveries'))->assertOk()->assertJsonCount(0, 'data.rows');
    }

    public function test_budget_warning_uses_available_ledger_funds_and_configured_threshold(): void
    {
        $cycle = BudgetCycle::query()->create(['mda_id' => $this->mda->id, 'year' => 2026, 'status' => 'approved']);
        $account = BudgetAccount::query()->create(['mda_id' => $this->mda->id, 'code' => 'CAP', 'name' => 'Capital', 'account_type' => 'capital']);
        $allocation = BudgetAllocation::query()->create(['mda_id' => $this->mda->id, 'budget_cycle_id' => $cycle->id, 'budget_account_id' => $account->id, 'title' => 'Equipment', 'approved_amount' => 1000]);
        $ledger = app(BudgetLedgerService::class);
        $ledger->approveAllocation($allocation, $this->officer);
        $commitment = BudgetCommitment::query()->create(['mda_id' => $this->mda->id, 'budget_cycle_id' => $cycle->id, 'budget_allocation_id' => $allocation->id, 'commitment_no' => 'COM-TEST', 'description' => 'Equipment purchase', 'amount' => 900, 'status' => 'draft']);
        $ledger->postCommitment($commitment, $this->officer);
        $this->getJson($this->url('?category=budget'))->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.amount', '100.00')->assertJsonPath('data.rows.0.available_percent', '10.0000');
        config(['procurement_alerts.low_budget_percent' => 5]);
        $this->getJson($this->url('?category=budget'))->assertOk()->assertJsonCount(0, 'data.rows');
        config(['procurement_alerts.low_budget_percent' => 10]);
        $ledger->releaseCommitment($commitment, 200, $this->officer);
        $this->getJson($this->url('?category=budget'))->assertOk()->assertJsonCount(0, 'data.rows');
        $cycle->update(['status' => 'closed']);
        $this->getJson($this->url('?category=budget'))->assertOk()->assertJsonCount(0, 'data.rows');
    }

    public function test_mda_and_module_access_are_rechecked_for_reads_and_personal_state_writes(): void
    {
        $fixture = $this->fixture();
        $key = $this->getJson($this->url())->assertOk()->json('data.rows.0.id');
        $other = User::factory()->mdaUser($this->otherMda)->create();
        $other->givePermissionTo($this->officer->getPermissionNames());
        $this->actingAs($other)->getJson($this->url('?mda_id='.$this->mda->id))->assertForbidden();
        $this->postJson($this->url('/read'), ['mda_id' => $this->otherMda->id, 'keys' => [$key], 'read' => true])->assertNotFound();
        $this->actingAs($this->officer)->getJson($this->url('?mda_id='.$this->otherMda->id))->assertForbidden();
        $this->officer->accessScopes()->create(['scope_type' => 'mda', 'mda_id' => $this->otherMda->id]);
        $this->getJson($this->url('?mda_id='.$this->otherMda->id))->assertOk()->assertJsonCount(0, 'data.rows');
        $this->postJson($this->url('/read'), ['mda_id' => $this->otherMda->id, 'keys' => [$key], 'read' => true])->assertNotFound();
        MdaModule::query()->where('mda_id', $this->mda->id)->where('module_id', Module::query()->where('code', 'budget_procurement')->value('id'))->update(['enabled' => false]);
        $this->getJson($this->url('/summary?mda_id='.$this->mda->id))->assertForbidden();
        $this->postJson($this->url('/read-all'), ['mda_id' => $this->mda->id])->assertForbidden();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.mda_id', $this->otherMda->id);
        $this->assertDatabaseCount('procurement_alert_reads', 0);
    }

    public function test_notification_filters_pagination_summary_and_input_validation(): void
    {
        $this->fixture();
        $summary = $this->getJson($this->url('/summary'))->assertOk()->json('data');
        $this->getJson($this->url('?per_page=1&page=2'))->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.unread_count', $summary['unread_count'])->assertJsonPath('data.meta.total', 5);
        $this->getJson($this->url('?category=unknown'))->assertUnprocessable();
        $this->postJson($this->url('/read'), ['keys' => ['forged'], 'read' => true])->assertUnprocessable();
        $this->postJson($this->url('/read'), ['keys' => [str_repeat('a', 64)], 'read' => true])->assertNotFound();
        $this->actingAs(User::factory()->mdaUser($this->mda)->create())->getJson($this->url())->assertForbidden();
        auth()->forgetGuards();
        $this->getJson($this->url())->assertUnauthorized();
    }

    private function fixture(): array
    {
        $mda = $this->mda->id;
        $request = ProcurementRequisition::query()->create(['mda_id' => $mda, 'requisition_no' => 'REQ-TEST', 'title' => 'Equipment', 'status' => 'submitted', 'requested_by' => $this->officer->id, 'submitted_at' => now()]);
        $request->lines()->create(['mda_id' => $mda, 'item_description' => 'Equipment', 'quantity' => 4, 'estimated_amount' => 400, 'status' => 'submitted']);
        $vendor = Vendor::query()->create(['mda_id' => $mda, 'vendor_no' => 'VEN-TEST', 'name' => 'Equipment Supplier']);
        $source = ProcurementSolicitation::query()->create(['mda_id' => $mda, 'procurement_requisition_id' => $request->id, 'solicitation_no' => 'RFQ-TEST', 'title' => 'Equipment', 'procurement_method' => 'rfq', 'status' => 'closed']);
        $bid = ProcurementBid::query()->create(['mda_id' => $mda, 'procurement_solicitation_id' => $source->id, 'vendor_id' => $vendor->id, 'bid_no' => 'BID-TEST', 'quoted_amount' => 400]);
        $award = ProcurementAward::query()->create(['mda_id' => $mda, 'procurement_solicitation_id' => $source->id, 'procurement_bid_id' => $bid->id, 'vendor_id' => $vendor->id, 'award_no' => 'AWD-TEST', 'award_amount' => 400, 'status' => 'recommended', 'recommended_by' => $this->officer->id, 'recommended_at' => now()]);
        $order = PurchaseOrder::query()->create(['mda_id' => $mda, 'procurement_award_id' => $award->id, 'procurement_solicitation_id' => $source->id, 'procurement_requisition_id' => $request->id, 'vendor_id' => $vendor->id, 'po_no' => 'PO-TEST', 'title' => 'Equipment order', 'total_amount' => 400, 'status' => 'issued', 'expected_delivery_date' => '2026-10-18']);
        $item = $order->items()->create(['mda_id' => $mda, 'item_description' => 'Equipment', 'quantity' => 4, 'unit_price' => 100, 'total_amount' => 400]);
        $invoice = SupplierInvoice::query()->create(['mda_id' => $mda, 'purchase_order_id' => $order->id, 'vendor_id' => $vendor->id, 'invoice_no' => 'INV-TEST', 'invoice_date' => '2026-10-17', 'due_date' => '2026-10-18', 'currency' => 'NGN', 'total_amount' => 200, 'status' => 'submitted', 'prepared_by' => $this->officer->id, 'submitted_at' => now()]);
        $payment = SupplierPayment::query()->create(['mda_id' => $mda, 'supplier_invoice_id' => $invoice->id, 'vendor_id' => $vendor->id, 'request_key' => Str::uuid(), 'payment_no' => 'PAY-TEST', 'amount' => 100, 'currency' => 'NGN', 'method' => 'bank_transfer', 'status' => 'submitted', 'prepared_by' => $this->maker->id, 'submitted_at' => now()]);

        return compact('request', 'vendor', 'source', 'bid', 'award', 'order', 'item', 'invoice', 'payment');
    }

    private function url(string $suffix = ''): string
    {
        return '/api/budget-procurement/notifications'.$suffix;
    }
}
