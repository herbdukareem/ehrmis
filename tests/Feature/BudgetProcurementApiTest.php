<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetAccount;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Budget\Models\BudgetTransaction;
use App\Domain\Budget\Services\BudgetLedgerService;
use App\Domain\Module\Models\MdaModule;
use App\Domain\Module\Models\Module;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Procurement\Models\ProcurementPlan;
use App\Domain\Procurement\Models\ProcurementReceipt;
use App\Domain\Procurement\Models\ProcurementReceiptItem;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Procurement\Models\SupplierPayment;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\ProcurementReportService;
use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanActivity;
use App\Domain\Workplan\Models\WorkplanObjective;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class BudgetProcurementApiTest extends TestCase
{
    use RefreshDatabase;

    private Mda $mda;

    private Mda $otherMda;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mda = Mda::factory()->create();
        $this->otherMda = Mda::factory()->create();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->officer = User::factory()->mdaUser($this->mda)->create();
        $this->officer->givePermissionTo([
            'view-budget-cycles', 'create-budget-cycles', 'approve-budget-cycles',
            'manage-budget-allocations', 'manage-budget-releases', 'manage-budget-adjustments',
            'view-procurement', 'manage-procurement-plans', 'create-requisitions',
            'approve-requisitions', 'review-budget-availability', 'manage-sourcing',
            'evaluate-bids', 'approve-awards', 'manage-purchase-orders',
            'record-procurement-receipts', 'manage-vendors',
            'view-supplier-payables', 'manage-supplier-invoices', 'approve-supplier-invoices', 'record-supplier-payments', 'approve-supplier-payments',
        ]);
        Storage::fake('local');
    }

    public function test_guests_and_users_without_permissions_cannot_use_the_api(): void
    {
        $this->getJson($this->url('cycles'))->assertUnauthorized();
        $user = User::factory()->mdaUser($this->mda)->create();
        $this->actingAs($user)->getJson($this->url('cycles'))->assertForbidden();
        $this->postJson($this->url('plans'), ['mda_id' => $this->mda->id, 'year' => 2026, 'title' => 'Plan'])->assertForbidden();
    }

    public function test_reports_reconcile_budget_delivery_and_opening_balances(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'UTC'));
        $ids = $this->workflow();
        $params = ['mda_id' => $this->mda->id, 'year' => 2026, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31'];
        $this->getJson($this->url('reports/budget-utilization').'?'.http_build_query($params))->assertOk()
            ->assertJsonPath('data.summary.currencies.NGN.allocated', '1100.00')
            ->assertJsonPath('data.summary.currencies.NGN.released', '800.00')
            ->assertJsonPath('data.summary.currencies.NGN.committed', '200.00')
            ->assertJsonPath('data.summary.currencies.NGN.spent', '200.00')
            ->assertJsonPath('data.summary.currencies.NGN.available', '700.00')
            ->assertJsonPath('data.summary.currencies.NGN.opening_available', '0.00');
        $this->getJson($this->url('reports/procurement-tracking').'?'.http_build_query($params))->assertOk()
            ->assertJsonPath('data.rows.0.sourcing', 1)->assertJsonPath('data.rows.0.awards', 1)
            ->assertJsonPath('data.rows.0.orders', 1)->assertJsonPath('data.rows.0.delivery_progress', 50);
        ProcurementReceipt::query()->findOrFail($ids['receipt'])->update(['posted_at' => '2026-10-20 12:00:00']);
        $this->getJson($this->url('reports/procurement-tracking').'?'.http_build_query([...$params, 'date_to' => '2026-10-19']))->assertOk()
            ->assertJsonPath('data.rows.0.delivery_progress', 0);
        $params['date_from'] = '2026-10-20';
        $this->getJson($this->url('reports/budget-utilization').'?'.http_build_query($params))->assertOk()
            ->assertJsonPath('data.summary.currencies.NGN.opening_available', '700.00')
            ->assertJsonPath('data.summary.currencies.NGN.period_spent', '0.00');
        $params['date_from'] = '2026-10-01';
        $params['date_to'] = '2026-10-18';
        $this->getJson($this->url('reports/budget-utilization').'?'.http_build_query($params))->assertOk()->assertJsonCount(0, 'data.rows');
        $params['date_to'] = '2026-10-31';
        $params['year'] = 2025;
        $this->getJson($this->url('reports/procurement-tracking').'?'.http_build_query($params))->assertOk()->assertJsonCount(0, 'data.rows');
        $params['year'] = 2026;
        $params['vendor_id'] = $ids['vendor'];
        $this->getJson($this->url('reports/dashboard').'?'.http_build_query($params))->assertOk()
            ->assertJsonMissingPath('data.reports.budget-utilization')->assertJsonPath('data.reports.procurement-tracking.summary.count', 1);
        $this->travelBack();
    }

    public function test_supplier_reports_retain_old_liabilities_and_exclude_later_settlements(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'UTC'));
        $ids = $this->workflow();
        $invoice = $this->supplierInvoice($ids, true);
        SupplierInvoice::query()->findOrFail($invoice)->update(['due_date' => '2026-10-21']);
        $approver = User::factory()->mdaUser($this->mda)->create(['name' => 'Payment Approver']);
        $approver->givePermissionTo('approve-supplier-payments');
        foreach (['2026-10-20' => 80, '2026-11-20' => 100] as $date => $amount) {
            $this->travelTo(Carbon::parse($date.' 12:00:00', 'UTC'));
            $payment = $this->actingAs($this->officer)->supplierPayment($invoice, $amount);
            $this->postJson($this->url('supplier-payments/'.$payment.'/submit'))->assertOk();
            $this->actingAs($approver)->postJson($this->url('supplier-payments/'.$payment.'/approve'))->assertOk();
            $this->actingAs($this->officer)->postJson($this->url('supplier-payments/'.$payment.'/mark-paid'), ['payment_reference' => 'REF-'.$payment, 'paid_date' => $date])->assertOk();
        }
        $params = ['mda_id' => $this->mda->id, 'year' => 2026, 'date_from' => '2026-10-25', 'date_to' => '2026-10-31', 'vendor_id' => $ids['vendor']];
        $this->getJson($this->url('reports/supplier-balances').'?'.http_build_query($params))->assertOk()
            ->assertJsonPath('data.summary.count', 1)->assertJsonPath('data.summary.currencies.NGN.invoiced', '200.00')
            ->assertJsonPath('data.summary.currencies.NGN.paid', '80.00')->assertJsonPath('data.summary.currencies.NGN.outstanding', '120.00')
            ->assertJsonPath('data.summary.currencies.NGN.overdue', '120.00')->assertJsonPath('data.summary.currencies.NGN.days_1_30', '120.00')
            ->assertJsonPath('data.summary.currencies.NGN.period_approved', '0.00')->assertJsonPath('data.summary.currencies.NGN.period_paid', '0.00');
        $params['date_from'] = '2026-10-01';
        $this->getJson($this->url('reports/payment-history').'?'.http_build_query($params))->assertOk()
            ->assertJsonPath('data.summary.count', 1)->assertJsonPath('data.summary.currencies.NGN.settled', '80.00')
            ->assertJsonPath('data.rows.0.approved_by', 'Payment Approver')->assertJsonPath('data.rows.0.paid_date', '2026-10-20');
        $this->assertSame(1, BudgetTransaction::query()->where('transaction_type', 'expenditure')->count());
        $this->travelBack();
    }

    public function test_report_permissions_mda_supplier_and_date_filters_are_enforced(): void
    {
        $this->actingAs($this->officer);
        $params = ['mda_id' => $this->mda->id, 'date_from' => '2026-01-01', 'date_to' => '2026-10-31'];
        $foreign = Vendor::query()->create(['mda_id' => $this->otherMda->id, 'name' => 'Foreign supplier', 'vendor_no' => 'FOREIGN']);
        $this->getJson($this->url('reports/supplier-balances').'?'.http_build_query([...$params, 'vendor_id' => $foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
        $this->getJson($this->url('reports/budget-utilization').'?'.http_build_query([...$params, 'mda_id' => $this->otherMda->id]))->assertForbidden();
        $this->getJson($this->url('reports/budget-utilization').'?'.http_build_query([...$params, 'date_from' => '2026-12-01']))->assertUnprocessable();
        $this->getJson($this->url('reports/budget-utilization').'?'.http_build_query([...$params, 'date_to' => 'bad-date']))->assertUnprocessable();
        $own = Vendor::query()->create(['mda_id' => $this->mda->id, 'name' => 'Own', 'vendor_no' => 'OWN']);
        $this->getJson($this->url('reports/budget-utilization').'?'.http_build_query([...$params, 'vendor_id' => $own->id]))->assertUnprocessable();
        $this->getJson($this->url('reports/budget-utilization/export/xlsx').'?'.http_build_query($params))->assertForbidden();
        $this->officer->accessScopes()->create(['scope_type' => 'mda', 'mda_id' => $this->otherMda->id]);
        $this->getJson($this->url('reports/supplier-balances').'?'.http_build_query([...$params, 'vendor_id' => $foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
        $viewer = User::factory()->mdaUser($this->mda)->create();
        $viewer->givePermissionTo(['view-procurement', 'export-procurement-reports']);
        $this->actingAs($viewer)->getJson($this->url('reports/dashboard').'?'.http_build_query($params))->assertOk()
            ->assertJsonMissingPath('data.reports.supplier-balances')->assertJsonMissingPath('data.reports.payment-history')->assertJsonMissingPath('data.reports.budget-utilization');
        $this->getJson($this->url('reports/supplier-balances/export/pdf').'?'.http_build_query($params))->assertForbidden();
        $this->getJson($this->url('reports/options'))->assertOk()->assertJsonMissingPath('data.reports.supplier-balances');
        MdaModule::query()->where('mda_id', $this->mda->id)->where('module_id', Module::query()->where('code', 'budget_procurement')->value('id'))->update(['enabled' => false]);
        $this->getJson($this->url('reports/dashboard').'?'.http_build_query($params))->assertForbidden();
        $this->actingAs($this->officer)->getJson($this->url('reports/options'))->assertOk()->assertJsonPath('data.mda_id', $this->otherMda->id);
    }

    public function test_report_exports_include_all_filtered_rows_safe_text_and_separate_currencies(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'UTC'));
        $ids = $this->workflow();
        $invoice = SupplierInvoice::query()->findOrFail($this->supplierInvoice($ids, true));
        $copy = $invoice->replicate();
        $copy->invoice_no = '=1+1';
        $copy->currency = 'USD';
        $copy->save();
        $this->supplierPayment($invoice->id, 50);
        $this->officer->givePermissionTo('export-procurement-reports');
        $params = ['mda_id' => $this->mda->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'per_page' => 1, 'page' => 2];
        $this->getJson($this->url('reports/supplier-balances').'?'.http_build_query($params))->assertOk()->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.summary.count', 2)->assertJsonPath('data.summary.currencies.NGN.outstanding', '200.00')->assertJsonPath('data.summary.currencies.USD.outstanding', '200.00');
        $xlsx = $this->get($this->url('reports/supplier-balances/export/xlsx').'?'.http_build_query($params))->assertOk();
        $book = IOFactory::load($xlsx->baseResponse->getFile()->getPathname());
        $sheet = $book->getActiveSheet();
        $cells = $sheet->toArray();
        $this->assertContains('INV-TEST', array_column($cells, 1));
        $this->assertContains('=1+1', array_column($cells, 1));
        $periodPaidRow = array_search('Period Paid (USD)', array_column($cells, 0), true);
        $this->assertNotFalse($periodPaidRow);
        $this->assertSame(0.0, (float) $sheet->getCell('B'.($periodPaidRow + 1))->getValue());
        $this->assertNotNull($sheet->getCell('B'.($periodPaidRow + 1))->getValue());
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() === '=1+1') {
                    $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
                }
            }
        }
        $pdf = $this->get($this->url('reports/supplier-balances/export/pdf').'?'.http_build_query($params))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        if (getenv('BP_REPORT_ARTIFACTS')) {
            $directory = storage_path('app/private/ui-checks/budget-procurement');
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($directory.'/supplier-balances.pdf', $pdf->getContent());
            copy($xlsx->baseResponse->getFile()->getPathname(), $directory.'/supplier-balances.xlsx');
        }
        foreach (['budget-utilization', 'procurement-tracking', 'payment-history'] as $type) {
            $this->get($this->url('reports/'.$type.'/export/xlsx').'?'.http_build_query($params))->assertOk();
            $otherPdf = $this->get($this->url('reports/'.$type.'/export/pdf').'?'.http_build_query($params))->assertOk();
            $this->assertStringStartsWith('%PDF-', $otherPdf->getContent());
            if (getenv('BP_REPORT_ARTIFACTS')) {
                file_put_contents($directory.'/'.$type.'.pdf', $otherPdf->getContent());
            }
        }
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'report.exported', 'auditable_type' => 'budget-procurement.supplier-balances']);
        try {
            app(ProcurementReportService::class)->build('supplier-balances', $params, 1, 1, 1);
            $this->fail('Oversized exports must not be silently truncated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('export', $exception->errors());
        }
        $this->travelBack();
    }

    public function test_report_cutoffs_use_lagos_approval_dates_and_separate_later_settlements(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 23:30:00', 'UTC'));
        $ids = $this->workflow();
        $invoice = $this->supplierInvoice($ids, true);
        $payment = $this->supplierPayment($invoice, 100);
        $this->postJson($this->url('supplier-payments/'.$payment.'/submit'))->assertOk();
        $approver = User::factory()->mdaUser($this->mda)->create();
        $approver->givePermissionTo('approve-supplier-payments');
        $this->actingAs($approver)->postJson($this->url('supplier-payments/'.$payment.'/approve'))->assertOk();
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        $this->actingAs($this->officer)->postJson($this->url('supplier-payments/'.$payment.'/mark-paid'), ['paid_date' => '2026-10-22', 'payment_reference' => 'LATER-SETTLEMENT'])->assertOk();
        $params = ['mda_id' => $this->mda->id, 'year' => 2026, 'date_from' => '2026-10-19', 'date_to' => '2026-10-19'];
        $this->getJson($this->url('reports/payment-history').'?'.http_build_query($params))->assertOk()->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.approved_date', '2026-10-19')->assertJsonPath('data.rows.0.status', 'paid')
            ->assertJsonPath('data.summary.currencies.NGN.settled', '0.00')->assertJsonPath('data.summary.currencies.NGN.approved_pending', '100.00');
        $this->getJson($this->url('reports/supplier-balances').'?'.http_build_query($params))->assertOk()->assertJsonPath('data.summary.currencies.NGN.outstanding', '200.00');
        $params['date_from'] = $params['date_to'] = '2026-10-18';
        $this->getJson($this->url('reports/payment-history').'?'.http_build_query($params))->assertOk()->assertJsonCount(0, 'data.rows');
        $this->getJson($this->url('reports/supplier-balances').'?'.http_build_query($params))->assertOk()->assertJsonCount(0, 'data.rows');
        $this->travelBack();
    }

    public function test_budget_and_procurement_flow_posts_balances_and_preserves_workflow_on_retries(): void
    {
        $ids = $this->workflow();

        $this->getJson($this->url('allocations/'.$ids['allocation']))->assertOk()
            ->assertJsonPath('data.balances.allocated_amount', 1100)
            ->assertJsonPath('data.balances.released_amount', 800)
            ->assertJsonPath('data.balances.committed_amount', 200)
            ->assertJsonPath('data.balances.actual_amount', 200)
            ->assertJsonPath('data.balances.available_amount', 700);

        $this->postJson($this->url('receipts/'.$ids['receipt'].'/post'))->assertOk();
        $this->assertSame(1, BudgetTransaction::query()->where('transaction_type', 'expenditure')->count());
        $this->postJson($this->url('awards/'.$ids['award'].'/purchase-order'))->assertOk()
            ->assertJsonPath('data.status', 'partially_received')
            ->assertJsonPath('data.items.0.status', 'partially_received');

        foreach (['cycles', 'accounts', 'allocations', 'releases', 'adjustments', 'commitments', 'plans', 'requisitions', 'vendors', 'solicitations', 'awards', 'purchase-orders', 'receipts'] as $path) {
            $this->getJson($this->url($path))->assertOk()->assertJsonCount(1, 'data');
        }
        $this->getJson($this->url('transactions'))->assertOk()->assertJsonStructure(['data', 'current_page', 'per_page', 'total']);
        $this->getJson($this->url('solicitations/'.$ids['solicitation']))->assertOk()->assertJsonCount(1, 'data.bids');
        $this->getJson($this->url('documents/'.$ids['document'].'/download'))->assertOk();
        $this->deleteJson($this->url('documents/'.$ids['document']))->assertOk();
        $this->assertDatabaseMissing('procurement_documents', ['id' => $ids['document']]);
    }

    public function test_every_write_route_requires_its_action_permission(): void
    {
        $ids = $this->workflow();
        $invoice = SupplierInvoice::query()->create(['mda_id' => $this->mda->id, 'purchase_order_id' => $ids['po'], 'vendor_id' => $ids['vendor'], 'invoice_no' => 'INV-PERM', 'invoice_date' => today(), 'currency' => 'NGN', 'total_amount' => 200, 'status' => 'approved']);
        $payment = SupplierPayment::query()->create(['mda_id' => $this->mda->id, 'supplier_invoice_id' => $invoice->id, 'vendor_id' => $ids['vendor'], 'request_key' => Str::uuid(), 'payment_no' => 'PAY-PERM', 'amount' => 100, 'currency' => 'NGN', 'method' => 'bank_transfer', 'status' => 'draft']);
        $viewer = User::factory()->mdaUser($this->mda)->create();
        $viewer->givePermissionTo(['view-budget-cycles', 'view-procurement']);
        $this->actingAs($viewer);
        $parameters = [
            'cycle' => $ids['cycle'], 'allocation' => $ids['allocation'], 'release' => $ids['release'],
            'adjustment' => $ids['adjustment'], 'plan' => $ids['plan'], 'requisition' => $ids['requisition'],
            'solicitation' => $ids['solicitation'], 'bid' => $ids['bid'], 'evaluation' => $ids['evaluation'],
            'award' => $ids['award'], 'purchaseOrder' => $ids['po'], 'receipt' => $ids['receipt'],
            'targetType' => 'vendors', 'targetId' => $ids['vendor'], 'document' => $ids['document'],
            'invoice' => $invoice->id, 'payment' => $payment->id,
        ];
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            // Notification read state is personal and does not mutate a business workflow.
            if (! str_starts_with((string) $route->getName(), 'api.budget-procurement.') || str_starts_with((string) $route->getName(), 'api.budget-procurement.notifications.') || in_array('GET', $route->methods(), true)) {
                continue;
            }
            $args = array_intersect_key($parameters, array_flip($route->parameterNames()));
            $this->json($route->methods()[0], route($route->getName(), $args), [])->assertForbidden();
            $checked++;
        }
        $this->assertGreaterThan(25, $checked);
        $this->getJson($this->url('cycles'))->assertOk();
        $this->getJson($this->url('purchase-orders'))->assertOk();
    }

    public function test_other_mda_records_cannot_be_listed_viewed_changed_or_downloaded(): void
    {
        $ids = $this->workflow();
        $otherOfficer = User::factory()->mdaUser($this->otherMda)->create();
        $otherOfficer->givePermissionTo($this->officer->getPermissionNames());
        $this->actingAs($otherOfficer);
        foreach (['cycles', 'accounts', 'allocations', 'releases', 'adjustments', 'commitments', 'transactions', 'plans', 'requisitions', 'vendors', 'solicitations', 'awards', 'purchase-orders', 'receipts'] as $path) {
            $this->getJson($this->url($path))->assertOk()->assertJsonCount(0, 'data');
        }
        foreach ([
            'cycles/'.$ids['cycle'], 'allocations/'.$ids['allocation'], 'plans/'.$ids['plan'],
            'requisitions/'.$ids['requisition'], 'vendors/'.$ids['vendor'], 'bids/'.$ids['bid'],
            'solicitations/'.$ids['solicitation'], 'awards/'.$ids['award'],
            'purchase-orders/'.$ids['po'], 'receipts/'.$ids['receipt'], 'documents/'.$ids['document'].'/download',
        ] as $path) {
            $this->getJson($this->url($path))->assertNotFound();
        }
        $this->postJson($this->url('awards/'.$ids['award'].'/approve'))->assertNotFound();
        $this->postJson($this->url('vendors/'.$ids['vendor'].'/documents'), ['title' => 'Injected', 'external_url' => 'https://example.com/file.pdf'])->assertNotFound();
        $this->deleteJson($this->url('documents/'.$ids['document']))->assertNotFound();
        $this->getJson($this->url('cycles').'?mda_id='.$this->mda->id)->assertForbidden();
        $this->postJson($this->url('plans'), ['mda_id' => $this->mda->id, 'year' => 2026, 'title' => 'Injected'])->assertForbidden();
    }

    public function test_related_ids_are_rejected_even_when_user_can_access_both_mdas(): void
    {
        $foreign = $this->allocation($this->otherMda);
        $foreignDepartment = Department::query()->create(['mda_id' => $this->otherMda->id, 'code' => 'ADMIN', 'name' => 'Admin', 'status' => 'active']);
        $foreignPlan = ProcurementPlan::query()->create(['mda_id' => $this->otherMda->id, 'year' => 2026, 'title' => 'Other plan']);
        $foreignVendor = Vendor::query()->create(['mda_id' => $this->otherMda->id, 'name' => 'Other supplier', 'vendor_no' => 'OTHER', 'status' => 'active']);
        $this->officer->accessScopes()->create(['scope_type' => 'mda', 'mda_id' => $this->otherMda->id]);
        $ids = $this->workflow();
        $this->postJson($this->url('plans'), [
            'mda_id' => $this->mda->id, 'year' => 2026, 'title' => 'Invalid', 'budget_cycle_id' => $foreign->budget_cycle_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('budget_cycle_id');
        $this->postJson($this->url('cycles/'.$ids['cycle'].'/allocations'), [
            'title' => 'Invalid', 'approved_amount' => 100, 'budget_account_id' => $foreign->budget_account_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('budget_account_id');
        $this->postJson($this->url('accounts'), [
            'mda_id' => $this->mda->id, 'name' => 'Invalid', 'code' => 'BAD', 'account_type' => 'capital', 'parent_id' => $foreign->budget_account_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson($this->url('plans/'.$ids['plan'].'/lines'), [
            'item_description' => 'Invalid', 'estimated_amount' => 50, 'budget_allocation_id' => $foreign->id, 'department_id' => $foreignDepartment->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['budget_allocation_id', 'department_id']);
        $this->postJson($this->url('requisitions'), [
            'mda_id' => $this->mda->id, 'title' => 'Invalid', 'procurement_plan_id' => $foreignPlan->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('procurement_plan_id');
        $this->postJson($this->url('solicitations/'.$ids['solicitation'].'/bids'), [
            'vendor_id' => $foreignVendor->id, 'quoted_amount' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
    }

    public function test_disabled_module_is_enforced_for_actual_record_despite_spoofed_mda(): void
    {
        $ids = $this->workflow();
        $this->officer->accessScopes()->create(['scope_type' => 'mda', 'mda_id' => $this->otherMda->id]);
        MdaModule::query()->where('mda_id', $this->mda->id)
            ->where('module_id', Module::query()->where('code', 'budget_procurement')->value('id'))
            ->update(['enabled' => false]);
        $this->getJson($this->url('cycles/'.$ids['cycle']).'?mda_id='.$this->otherMda->id)->assertForbidden();
        $this->postJson($this->url('receipts/'.$ids['receipt'].'/post'), ['mda_id' => $this->otherMda->id])->assertForbidden();
        $this->getJson($this->url('cycles').'?mda_id='.$this->otherMda->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_status_and_actor_injection_cannot_skip_approvals(): void
    {
        $creator = User::factory()->mdaUser($this->mda)->create();
        $creator->givePermissionTo(['create-budget-cycles', 'manage-procurement-plans', 'create-requisitions']);
        $this->actingAs($creator);
        $cycle = $this->postJson($this->url('cycles'), [
            'mda_id' => $this->mda->id, 'year' => 2026, 'status' => 'approved', 'approved_by' => $this->officer->id,
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.approved_by', null)->json('data.id');
        $this->postJson($this->url('cycles/'.$cycle.'/approve'))->assertForbidden();
        $this->postJson($this->url('plans'), [
            'mda_id' => $this->mda->id, 'year' => 2026, 'title' => 'Plan', 'status' => 'approved',
        ])->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->postJson($this->url('requisitions'), [
            'mda_id' => $this->mda->id, 'title' => 'Request', 'status' => 'approved', 'requested_by' => $this->officer->id,
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.requested_by', $creator->id);
    }

    public function test_invalid_amounts_duplicate_cycles_and_overspending_return_validation_errors(): void
    {
        $allocation = $this->allocation($this->mda);
        $this->actingAs($this->officer);
        $this->postJson($this->url('cycles'), ['mda_id' => $this->mda->id, 'year' => 2026])->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->postJson($this->url('allocations/'.$allocation->id.'/releases'), ['amount' => -2, 'release_date' => '2026-10-15'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $release = $this->postJson($this->url('allocations/'.$allocation->id.'/releases'), [
            'amount' => 2000, 'release_date' => '2026-10-15',
        ])->assertCreated()->json('data.id');
        $this->postJson($this->url('releases/'.$release.'/post'))->assertUnprocessable();
        $this->assertDatabaseHas('budget_releases', ['id' => $release, 'status' => 'draft']);
        $this->assertDatabaseMissing('budget_transactions', ['transaction_type' => 'release']);
        $this->getJson($this->url('cycles').'?per_page=10000')->assertUnprocessable();
    }

    public function test_workplan_import_and_manual_plan_lines_validate_source_mda_and_year(): void
    {
        $plan = ProcurementPlan::query()->create(['mda_id' => $this->mda->id, 'year' => 2026, 'title' => 'Plan']);
        $workplan = Workplan::query()->create(['mda_id' => $this->mda->id, 'year' => 2026, 'revision_no' => 1, 'title' => 'Workplan', 'status' => 'approved']);
        $objective = WorkplanObjective::query()->create(['mda_id' => $this->mda->id, 'workplan_id' => $workplan->id, 'code' => 'OBJ', 'title' => 'Objective']);
        $activity = WorkplanActivity::query()->create([
            'mda_id' => $this->mda->id, 'workplan_objective_id' => $objective->id,
            'activity_code' => 'ACT', 'title' => 'Purchase equipment', 'planned_cost' => 200, 'status' => 'not_started',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ]);
        $this->actingAs($this->officer);
        $this->postJson($this->url('plans/'.$plan->id.'/import-workplan'), ['workplan_id' => $workplan->id])
            ->assertOk()->assertJsonPath('data.lines_synced', 1);
        $this->postJson($this->url('plans/'.$plan->id.'/lines'), [
            'item_description' => 'Manual equipment', 'estimated_amount' => 100, 'workplan_activity_id' => $activity->id,
        ])->assertCreated()->assertJsonPath('data.workplan_activity_id', $activity->id);
        $workplan->update(['year' => 2025]);
        $this->postJson($this->url('plans/'.$plan->id.'/import-workplan'), ['workplan_id' => $workplan->id])
            ->assertUnprocessable()->assertJsonValidationErrors('workplan_id');
        $workplan->update(['year' => 2026, 'status' => 'draft']);
        $this->postJson($this->url('plans/'.$plan->id.'/import-workplan'), ['workplan_id' => $workplan->id])->assertUnprocessable();
    }

    public function test_receipt_items_must_belong_to_the_selected_order_and_quantities_must_balance(): void
    {
        $ids = $this->workflow();
        $source = $this->postJson($this->url('requisitions/'.$ids['requisition'].'/solicitations'), ['procurement_method' => 'rfq'])->assertCreated()->json('data.id');
        $this->postJson($this->url('solicitations/'.$source.'/publish'))->assertOk();
        $bid = $this->postJson($this->url('solicitations/'.$source.'/bids'), ['vendor_id' => $ids['vendor'], 'quoted_amount' => 400])->assertCreated()->json('data.id');
        $evaluation = $this->postJson($this->url('bids/'.$bid.'/evaluate'), ['technical_score' => 50])->assertOk()->json('data.id');
        $award = $this->postJson($this->url('evaluations/'.$evaluation.'/recommend'))->assertOk()->json('data.id');
        $this->postJson($this->url('awards/'.$award.'/approve'))->assertOk();
        $otherItemId = $this->postJson($this->url('awards/'.$award.'/purchase-order'))->assertCreated()->json('data.items.0.id');
        $receipt = $this->postJson($this->url('purchase-orders/'.$ids['po'].'/receipts'))->assertCreated()->json('data.id');
        $this->postJson($this->url('receipts/'.$receipt.'/items'), [
            'purchase_order_item_id' => $otherItemId, 'quantity_received' => 1, 'quantity_accepted' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('purchase_order_item_id');
        $ownItem = PurchaseOrder::query()->findOrFail($ids['po'])->items()->firstOrFail();
        $this->postJson($this->url('receipts/'.$receipt.'/items'), [
            'purchase_order_item_id' => $ownItem->id, 'quantity_received' => 2, 'quantity_accepted' => 1, 'quantity_rejected' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity_rejected');
        $this->assertDatabaseMissing('procurement_receipt_items', ['procurement_receipt_id' => $receipt]);
    }

    public function test_form_options_are_scoped_to_enabled_accessible_mdas(): void
    {
        $own = $this->allocation($this->mda);
        $foreign = $this->allocation($this->otherMda);
        $this->actingAs($this->officer)->getJson($this->url('options'))
            ->assertOk()->assertJsonCount(1, 'data.mdas')
            ->assertJsonPath('data.mda_id', $this->mda->id)
            ->assertJsonPath('data.allocations.0.id', $own->id)
            ->assertJsonCount(1, 'data.cycles');
        $this->getJson($this->url('options').'?mda_id='.$this->otherMda->id)->assertForbidden();
        $this->officer->accessScopes()->create(['scope_type' => 'mda', 'mda_id' => $this->otherMda->id]);
        $this->getJson($this->url('options').'?mda_id='.$this->otherMda->id)
            ->assertOk()->assertJsonCount(2, 'data.mdas')->assertJsonPath('data.allocations.0.id', $foreign->id);
        MdaModule::query()->where('mda_id', $this->otherMda->id)
            ->where('module_id', Module::query()->where('code', 'budget_procurement')->value('id'))
            ->update(['enabled' => false]);
        $this->getJson($this->url('options'))->assertOk()->assertJsonCount(1, 'data.mdas');
        $this->getJson($this->url('options').'?mda_id='.$this->otherMda->id)->assertForbidden();
    }

    public function test_procurement_only_lookup_access_does_not_expose_budget_records(): void
    {
        $this->allocation($this->mda);
        $viewer = User::factory()->mdaUser($this->mda)->create();
        $viewer->givePermissionTo('view-procurement');
        $this->actingAs($viewer)->getJson($this->url('options'))->assertOk()
            ->assertJsonCount(0, 'data.cycles')->assertJsonCount(0, 'data.accounts')
            ->assertJsonCount(0, 'data.allocations')->assertJsonCount(0, 'data.workplans');
        $viewer->revokePermissionTo('view-procurement');
        $this->getJson($this->url('options'))->assertForbidden();
    }

    public function test_list_search_and_pagination_preserve_mda_boundaries(): void
    {
        ProcurementPlan::query()->create(['mda_id' => $this->otherMda->id, 'year' => 2026, 'title' => 'Needle foreign']);
        ProcurementPlan::query()->create(['mda_id' => $this->mda->id, 'year' => 2026, 'title' => 'Needle own']);
        ProcurementPlan::query()->create(['mda_id' => $this->mda->id, 'year' => 2026, 'title' => 'Other own']);
        $this->actingAs($this->officer)->getJson($this->url('plans').'?search=Needle&per_page=1')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.title', 'Needle own');
        $this->getJson($this->url('plans').'?search=foreign')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_return_correct_resubmit_and_reject_require_reasons_and_keep_history(): void
    {
        [$request, $line] = $this->draftRequest();
        $base = 'requisitions/'.$request;
        $this->postJson($this->url($base.'/submit'))->assertOk();
        $this->postJson($this->url($base.'/return'))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($this->url($base.'/return'), ['reason' => 'Correct quantity'])->assertOk()->assertJsonPath('data.status', 'returned');
        $this->postJson($this->url($base.'/correct'), ['reason' => 'Updated request', 'line_id' => $line, 'quantity' => 2, 'estimated_amount' => 200, 'title' => 'Corrected'])->assertOk()->assertJsonPath('data.total_amount', '200.00');
        $this->postJson($this->url($base.'/submit'))->assertOk();
        $this->postJson($this->url($base.'/reject'), ['reason' => 'Not needed'])->assertOk()->assertJsonPath('data.rejection_reason', 'Not needed');
        foreach (['submit', 'approve', 'correct', 'return'] as $action) {
            $this->postJson($this->url($base.'/'.$action), ['reason' => 'Retry'])->assertUnprocessable();
        }
        $history = $this->getJson($this->url($base))->assertOk()->json('data.history');
        $correction = collect($history)->firstWhere('action', 'correct');
        $this->assertSame('Updated request', $correction['reason']);
        $this->assertSame($this->officer->name, $correction['actor']);
        $this->assertSame('400.00', $correction['before']['total_amount']);
        $this->assertSame('200.00', $correction['after']['total_amount']);
    }

    public function test_corrections_cannot_target_another_requests_line_or_inject_status(): void
    {
        [$request, $line] = $this->draftRequest();
        [$otherRequest, $otherLine] = $this->draftRequest();
        $this->postJson($this->url('requisitions/'.$request.'/correct'), ['reason' => 'Change', 'line_id' => $otherLine, 'estimated_amount' => 1])->assertNotFound();
        $this->postJson($this->url('requisitions/'.$request.'/correct'), ['reason' => 'Change', 'status' => 'approved', 'approved_by' => $this->officer->id])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson($this->url('requisitions/'.$request.'/correct'), ['reason' => 'Remove', 'line_id' => $line, 'remove_line' => true])->assertOk()->assertJsonPath('data.total_amount', '0.00');
        $this->postJson($this->url('requisitions/'.$request.'/submit'))->assertUnprocessable();
        $this->assertDatabaseHas('procurement_requisition_lines', ['id' => $otherLine, 'procurement_requisition_id' => $otherRequest]);
    }

    public function test_partial_order_cancellation_releases_only_unused_commitment_once(): void
    {
        $ids = $this->workflow();
        $draft = $this->postJson($this->url('purchase-orders/'.$ids['po'].'/receipts'))->assertCreated()->json('data.id');
        $item = PurchaseOrder::query()->findOrFail($ids['po'])->items()->firstOrFail();
        $this->postJson($this->url('receipts/'.$draft.'/items'), ['purchase_order_item_id' => $item->id, 'quantity_received' => 1, 'quantity_accepted' => 1])->assertCreated();
        $this->postJson($this->url('requisitions/'.$ids['requisition'].'/cancel'), ['reason' => 'Stop'])->assertUnprocessable();
        $this->postJson($this->url('purchase-orders/'.$ids['po'].'/cancel'), ['reason' => 'No further delivery'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($this->url('purchase-orders/'.$ids['po'].'/cancel'), ['reason' => 'Retry'])->assertOk();
        $this->postJson($this->url('receipts/'.$draft.'/post'))->assertUnprocessable();
        $this->assertDatabaseHas('procurement_receipts', ['id' => $draft, 'status' => 'cancelled']);
        $this->getJson($this->url('allocations/'.$ids['allocation']))->assertOk()->assertJsonPath('data.balances.actual_amount', 200)->assertJsonPath('data.balances.committed_amount', 0)->assertJsonPath('data.balances.available_amount', 900);
        $this->assertSame(1, BudgetTransaction::query()->where('transaction_type', 'commitment_release')->count());
        $this->postJson($this->url('requisitions/'.$ids['requisition'].'/cancel'), ['reason' => 'Cancelled procurement'])->assertOk();
        $this->postJson($this->url('evaluations/'.$ids['evaluation'].'/recommend'))->assertUnprocessable();
        $this->postJson($this->url('purchase-orders/'.$ids['po'].'/receipts'))->assertUnprocessable();
    }

    public function test_cancel_approved_request_without_orders_releases_commitments_and_stops_sourcing(): void
    {
        [$request] = $this->draftRequest();
        $this->postJson($this->url('requisitions/'.$request.'/submit'))->assertOk();
        $this->postJson($this->url('requisitions/'.$request.'/approve'))->assertOk();
        $source = $this->postJson($this->url('requisitions/'.$request.'/solicitations'), ['procurement_method' => 'rfq'])->assertCreated()->json('data.id');
        $this->postJson($this->url('requisitions/'.$request.'/cancel'), ['reason' => 'Withdrawn'])->assertOk();
        $this->postJson($this->url('solicitations/'.$source.'/publish'))->assertUnprocessable();
        $this->assertSame(-400.0, (float) BudgetTransaction::query()->where('transaction_type', 'commitment_release')->sum('signed_amount'));
    }

    public function test_rejected_award_can_be_recommended_again_without_erasing_its_rejection_history(): void
    {
        $ids = $this->workflow();
        $source = $this->postJson($this->url('requisitions/'.$ids['requisition'].'/solicitations'), ['procurement_method' => 'rfq'])->assertCreated()->json('data.id');
        $this->postJson($this->url('solicitations/'.$source.'/publish'))->assertOk();
        $bid = $this->postJson($this->url('solicitations/'.$source.'/bids'), ['vendor_id' => $ids['vendor'], 'quoted_amount' => 100])->assertCreated()->json('data.id');
        $evaluation = $this->postJson($this->url('bids/'.$bid.'/evaluate'), ['technical_score' => 30])->assertOk()->json('data.id');
        $award = $this->postJson($this->url('evaluations/'.$evaluation.'/recommend'))->assertOk()->json('data.id');
        $this->postJson($this->url('awards/'.$award.'/reject'), ['reason' => 'Review price'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson($this->url('awards/'.$award.'/approve'))->assertUnprocessable();
        $this->postJson($this->url('evaluations/'.$evaluation.'/recommend'))->assertOk()->assertJsonPath('data.status', 'recommended')->assertJsonPath('data.rejection_reason', null);
        $history = $this->getJson($this->url('awards/'.$award))->assertOk()->json('data.history');
        $this->assertSame('Review price', collect($history)->firstWhere('action', 'reject')['reason']);
        $this->postJson($this->url('awards/'.$ids['award'].'/reject'), ['reason' => 'Too late'])->assertUnprocessable();
    }

    public function test_plan_amendments_are_audited_and_cannot_rewrite_consumed_lines(): void
    {
        $ids = $this->workflow();
        $line = ProcurementPlan::query()->findOrFail($ids['plan'])->lines()->firstOrFail();
        $this->postJson($this->url('plans/'.$ids['plan'].'/amend'), ['reason' => 'Updated title', 'title' => 'Revised plan'])->assertOk()->assertJsonPath('data.title', 'Revised plan');
        $this->postJson($this->url('plans/'.$ids['plan'].'/amend'), ['reason' => 'Change used line', 'line_id' => $line->id, 'estimated_amount' => 10])->assertUnprocessable();
        $this->postJson($this->url('plans/'.$ids['plan'].'/close'), ['reason' => 'Close'])->assertUnprocessable();
        $newLine = $this->postJson($this->url('plans/'.$ids['plan'].'/lines'), ['item_description' => 'Additional', 'estimated_amount' => 100])->assertCreated()->json('data.id');
        $this->postJson($this->url('plans/'.$ids['plan'].'/amend'), ['reason' => 'Revised estimate', 'line_id' => $newLine, 'estimated_amount' => 80])->assertOk()->assertJsonPath('data.summary.estimated_amount', 1080);
    }

    public function test_order_amendments_preserve_receipts_and_cannot_exceed_approved_amounts(): void
    {
        $ids = $this->workflow();
        $base = 'purchase-orders/'.$ids['po'];
        $item = PurchaseOrder::query()->findOrFail($ids['po'])->items()->firstOrFail();
        $this->postJson($this->url($base.'/amend'), ['reason' => 'Reprice', 'item_id' => $item->id, 'quantity' => 4, 'unit_price' => 90])->assertUnprocessable();
        $this->postJson($this->url($base.'/amend'), ['reason' => 'Reduce below accepted', 'item_id' => $item->id, 'quantity' => 1])->assertUnprocessable();
        $this->postJson($this->url($base.'/amend'), ['reason' => 'Over award', 'item_id' => $item->id, 'quantity' => 5])->assertUnprocessable();
        $this->postJson($this->url($base.'/amend'), ['reason' => 'Reduce remaining delivery', 'item_id' => $item->id, 'quantity' => 3])->assertOk()->assertJsonPath('data.total_amount', '300.00');
        $draft = $this->postJson($this->url($base.'/receipts'))->assertCreated()->json('data.id');
        $this->postJson($this->url($base.'/amend'), ['reason' => 'Change terms', 'terms' => 'New'])->assertUnprocessable();
        $this->postJson($this->url('receipts/'.$draft.'/cancel'), ['reason' => 'Duplicate'])->assertOk();
        $this->postJson($this->url($base.'/amend'), ['reason' => 'Change terms', 'terms' => 'New'])->assertOk();
        $this->assertDatabaseHas('procurement_receipt_items', ['procurement_receipt_id' => $ids['receipt'], 'accepted_amount' => 200]);
        $this->assertSame(200.0, (float) BudgetTransaction::query()->where('transaction_type', 'expenditure')->sum('amount'));
    }

    public function test_completed_order_request_and_plan_can_close_without_releasing_spent_funds(): void
    {
        $ids = $this->workflow();
        $orderBase = 'purchase-orders/'.$ids['po'];
        $item = PurchaseOrder::query()->findOrFail($ids['po'])->items()->firstOrFail();
        $this->postJson($this->url($orderBase.'/close'), ['reason' => 'Premature'])->assertUnprocessable();
        $this->postJson($this->url($orderBase.'/amend'), ['reason' => 'Reduce final scope', 'item_id' => $item->id, 'quantity' => 2])->assertOk()->assertJsonPath('data.status', 'received');
        $this->postJson($this->url($orderBase.'/close'), ['reason' => 'Complete'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson($this->url($orderBase.'/close'), ['reason' => 'Retry'])->assertOk();
        $this->postJson($this->url('requisitions/'.$ids['requisition'].'/close'), ['reason' => 'Complete'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson($this->url('plans/'.$ids['plan'].'/close'), ['reason' => 'Complete'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson($this->url($orderBase.'/amend'), ['reason' => 'Late', 'terms' => 'Changed'])->assertUnprocessable();
        $this->postJson($this->url('plans/'.$ids['plan'].'/amend'), ['reason' => 'Late', 'title' => 'Changed'])->assertUnprocessable();
        $this->postJson($this->url('requisitions'), ['mda_id' => $this->mda->id, 'title' => 'Late', 'procurement_plan_id' => $ids['plan']])->assertUnprocessable();
        $this->getJson($this->url('allocations/'.$ids['allocation']))->assertOk()->assertJsonPath('data.balances.actual_amount', 200)->assertJsonPath('data.balances.committed_amount', 0);
    }

    public function test_new_workflow_actions_and_history_are_mda_scoped(): void
    {
        $ids = $this->workflow();
        $other = User::factory()->mdaUser($this->otherMda)->create();
        $other->givePermissionTo($this->officer->getPermissionNames());
        $this->actingAs($other);
        foreach (['requisitions/'.$ids['requisition'].'/cancel', 'requisitions/'.$ids['requisition'].'/return', 'plans/'.$ids['plan'].'/amend', 'purchase-orders/'.$ids['po'].'/cancel', 'awards/'.$ids['award'].'/reject', 'receipts/'.$ids['receipt'].'/cancel'] as $path) {
            $this->postJson($this->url($path), ['reason' => 'Foreign'])->assertNotFound();
        }
        $this->getJson($this->url('plans/'.$ids['plan']))->assertNotFound();
    }

    public function test_cancellation_keeps_commitments_needed_by_another_active_order(): void
    {
        $ids = $this->workflow();
        $source = $this->postJson($this->url('requisitions/'.$ids['requisition'].'/solicitations'), ['procurement_method' => 'rfq'])->assertCreated()->json('data.id');
        $this->postJson($this->url('solicitations/'.$source.'/publish'))->assertOk();
        $bid = $this->postJson($this->url('solicitations/'.$source.'/bids'), ['vendor_id' => $ids['vendor'], 'quoted_amount' => 100])->assertCreated()->json('data.id');
        $evaluation = $this->postJson($this->url('bids/'.$bid.'/evaluate'), ['technical_score' => 30])->assertOk()->json('data.id');
        $award = $this->postJson($this->url('evaluations/'.$evaluation.'/recommend'))->assertOk()->json('data.id');
        $this->postJson($this->url('awards/'.$award.'/approve'))->assertOk();
        $order = $this->postJson($this->url('awards/'.$award.'/purchase-order'))->assertCreated()->json('data');
        $this->postJson($this->url('purchase-orders/'.$order['id'].'/amend'), [
            'reason' => 'Overlaps another order reservation', 'item_id' => $order['items'][0]['id'], 'quantity' => 2, 'unit_price' => 50,
        ])->assertUnprocessable();
        $this->postJson($this->url('purchase-orders/'.$ids['po'].'/cancel'), ['reason' => 'Retain the other supplier'])->assertOk();
        $this->getJson($this->url('allocations/'.$ids['allocation']))->assertOk()->assertJsonPath('data.balances.committed_amount', 100)->assertJsonPath('data.balances.actual_amount', 200);
        $this->postJson($this->url('purchase-orders/'.$order['id'].'/amend'), [
            'reason' => 'Revised unreceived quantities', 'item_id' => $order['items'][0]['id'], 'quantity' => 2, 'unit_price' => 50,
        ])->assertOk()->assertJsonPath('data.items.0.unit_price', '50.00');
        $this->postJson($this->url('purchase-orders/'.$order['id'].'/cancel'), ['reason' => 'All cancelled'])->assertOk();
        $this->getJson($this->url('allocations/'.$ids['allocation']))->assertOk()->assertJsonPath('data.balances.committed_amount', 0)->assertJsonPath('data.balances.actual_amount', 200);
    }

    public function test_used_plan_line_cannot_be_overwritten_through_reimport(): void
    {
        $ids = $this->workflow();
        BudgetAllocation::query()->findOrFail($ids['allocation'])->update(['title' => 'Revised source title']);
        $this->postJson($this->url('plans/'.$ids['plan'].'/import-allocations'))->assertUnprocessable();
        $this->getJson($this->url('plans/'.$ids['plan']))->assertOk()->assertJsonPath('data.lines.0.item_description', 'Equipment');
    }

    public function test_supplier_invoice_matching_partial_payments_and_settlement_do_not_double_count_expenditure(): void
    {
        $ids = $this->workflow();
        $transactions = BudgetTransaction::query()->count();
        $invoice = $this->supplierInvoice($ids, true);
        $payment = $this->supplierPayment($invoice, '75.25');
        $this->postJson($this->url('supplier-payments/'.$payment.'/mark-paid'), ['payment_reference' => 'EARLY', 'paid_date' => today()->toDateString()])->assertUnprocessable();
        $this->postJson($this->url('supplier-payments/'.$payment.'/submit'))->assertOk();
        $this->getJson($this->url('supplier-invoices/'.$invoice))->assertOk()->assertJsonPath('data.paid_amount', '0.00')->assertJsonPath('data.reserved_payment_amount', '75.25')->assertJsonPath('data.available_for_payment', '124.75');
        $this->postJson($this->url('supplier-payments/'.$payment.'/approve'))->assertUnprocessable();
        $approver = User::factory()->mdaUser($this->mda)->create();
        $approver->givePermissionTo(['view-supplier-payables', 'approve-supplier-payments']);
        $this->actingAs($approver)->postJson($this->url('supplier-payments/'.$payment.'/approve'))->assertOk();
        $settlement = ['payment_reference' => 'BANK-001', 'paid_date' => today()->toDateString()];
        $this->actingAs($this->officer)->postJson($this->url('supplier-payments/'.$payment.'/mark-paid'), $settlement)->assertOk();
        $this->postJson($this->url('supplier-payments/'.$payment.'/mark-paid'), $settlement)->assertOk();
        $this->getJson($this->url('supplier-invoices/'.$invoice))->assertOk()->assertJsonPath('data.paid_amount', '75.25')->assertJsonPath('data.outstanding_amount', '124.75')->assertJsonPath('data.payment_status', 'partially_paid');
        $second = $this->supplierPayment($invoice, '124.75');
        $this->postJson($this->url('supplier-payments/'.$second.'/submit'))->assertOk();
        $this->actingAs($approver)->postJson($this->url('supplier-payments/'.$second.'/approve'))->assertOk();
        $this->actingAs($this->officer)->postJson($this->url('supplier-payments/'.$second.'/mark-paid'), ['payment_reference' => 'BANK-002', 'paid_date' => today()->toDateString()])->assertOk();
        $this->getJson($this->url('supplier-invoices/'.$invoice))->assertOk()->assertJsonPath('data.outstanding_amount', '0.00')->assertJsonPath('data.payment_status', 'paid');
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/payments'), ['request_key' => Str::uuid(), 'method' => 'bank_transfer', 'amount' => 0.01])->assertUnprocessable();
        $this->assertSame($transactions, BudgetTransaction::query()->count());
        $this->getJson($this->url('supplier-payments/'.$payment))->assertOk()->assertJsonPath('data.history.0.action', 'mark-paid');
    }

    public function test_invoice_total_must_match_and_prices_cannot_exceed_accepted_order_prices(): void
    {
        $ids = $this->workflow();
        $invoice = $this->supplierInvoice($ids, false, 250);
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/submit'))->assertUnprocessable();
        $receiptItem = ProcurementReceiptItem::query()->where('procurement_receipt_id', $ids['receipt'])->firstOrFail();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/items'), ['procurement_receipt_item_id' => $receiptItem->id, 'quantity' => 2, 'unit_price' => 101])->assertUnprocessable();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/update'), ['total_amount' => 200, 'reason' => 'Corrected total'])->assertOk();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/submit'))->assertOk();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/return'), ['reason' => 'Check supplier details'])->assertOk();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/submit'))->assertOk();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/approve'))->assertOk();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/update'), ['total_amount' => 200, 'reason' => 'Late change'])->assertUnprocessable();
    }

    public function test_invoice_numbers_and_receipt_quantities_cannot_be_billed_twice(): void
    {
        $ids = $this->workflow();
        $invoice = $this->supplierInvoice($ids);
        $this->postJson($this->url('supplier-invoices'), ['mda_id' => $this->mda->id, 'purchase_order_id' => $ids['po'], 'invoice_no' => ' inv-test ', 'invoice_date' => today()->toDateString(), 'total_amount' => 200])->assertUnprocessable();
        $second = $this->postJson($this->url('supplier-invoices'), ['mda_id' => $this->mda->id, 'purchase_order_id' => $ids['po'], 'invoice_no' => 'INV-SECOND', 'invoice_date' => today()->toDateString(), 'total_amount' => 200])->assertCreated()->json('data.id');
        $item = ProcurementReceiptItem::query()->where('procurement_receipt_id', $ids['receipt'])->firstOrFail();
        $this->postJson($this->url('supplier-invoices/'.$second.'/items'), ['procurement_receipt_item_id' => $item->id, 'quantity' => 1])->assertUnprocessable();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/cancel'), ['reason' => 'Duplicate draft'])->assertOk();
        $this->postJson($this->url('supplier-invoices/'.$second.'/items'), ['procurement_receipt_item_id' => $item->id, 'quantity' => 2])->assertCreated();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/submit'))->assertUnprocessable();
    }

    public function test_payment_reservations_prevent_overpayment_and_release_on_cancellation_or_rejection(): void
    {
        $ids = $this->workflow();
        $invoice = $this->supplierInvoice($ids, true);
        $first = $this->supplierPayment($invoice, 150);
        $second = $this->supplierPayment($invoice, 150);
        $this->postJson($this->url('supplier-payments/'.$first.'/submit'))->assertOk();
        $this->postJson($this->url('supplier-payments/'.$second.'/submit'))->assertUnprocessable();
        $this->postJson($this->url('supplier-payments/'.$first.'/cancel'))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($this->url('supplier-payments/'.$first.'/cancel'), ['reason' => 'Incorrect split'])->assertOk();
        $this->postJson($this->url('supplier-payments/'.$second.'/submit'))->assertOk();
        $this->postJson($this->url('supplier-payments/'.$second.'/reject'), ['reason' => 'Wrong method'])->assertOk();
        $this->getJson($this->url('supplier-invoices/'.$invoice))->assertOk()->assertJsonPath('data.available_for_payment', '200.00');
        $this->postJson($this->url('supplier-payments/'.$second.'/approve'))->assertUnprocessable();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/payments'), ['request_key' => Str::uuid(), 'method' => 'cash', 'amount' => 200.01])->assertUnprocessable();
    }

    public function test_payment_creation_and_settlement_are_idempotent_and_references_are_unique(): void
    {
        $ids = $this->workflow();
        $invoice = $this->supplierInvoice($ids, true);
        $body = ['request_key' => (string) Str::uuid(), 'method' => 'cash', 'amount' => 100, 'status' => 'paid', 'approved_by' => $this->officer->id];
        $payment = $this->postJson($this->url('supplier-invoices/'.$invoice.'/payments'), $body)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.approved_by', null)->json('data.id');
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/payments'), $body)->assertCreated()->assertJsonPath('data.id', $payment);
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/payments'), [...$body, 'amount' => 101])->assertUnprocessable();
        $approver = User::factory()->mdaUser($this->mda)->create();
        $approver->givePermissionTo('approve-supplier-payments');
        foreach ([$payment, $this->supplierPayment($invoice, 100)] as $index => $id) {
            $this->postJson($this->url('supplier-payments/'.$id.'/submit'))->assertOk();
            $this->actingAs($approver)->postJson($this->url('supplier-payments/'.$id.'/approve'))->assertOk();
            $this->actingAs($this->officer);
            if ($index === 0) {
                $this->postJson($this->url('supplier-payments/'.$id.'/mark-paid'), ['payment_reference' => 'REF-UNIQUE', 'paid_date' => today()->toDateString()])->assertOk();
            } else {
                $this->postJson($this->url('supplier-payments/'.$id.'/mark-paid'), ['payment_reference' => 'ref-unique', 'paid_date' => today()->toDateString()])->assertUnprocessable();
            }
        }
        $this->postJson($this->url('supplier-payments/'.$payment.'/mark-paid'), ['payment_reference' => 'OTHER', 'paid_date' => today()->toDateString()])->assertUnprocessable();
        $this->postJson($this->url('supplier-payments/'.$payment.'/cancel'), ['reason' => 'Cannot undo paid'])->assertUnprocessable();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/cancel'), ['reason' => 'Cannot erase liability'])->assertUnprocessable();
    }

    public function test_payables_and_lookups_require_finance_permission_and_respect_mda_scope(): void
    {
        $ids = $this->workflow();
        $invoice = $this->supplierInvoice($ids, true);
        $payment = $this->supplierPayment($invoice, 100);
        $viewer = User::factory()->mdaUser($this->mda)->create();
        $viewer->givePermissionTo('view-procurement');
        $this->actingAs($viewer)->getJson($this->url('supplier-invoices'))->assertForbidden();
        $this->getJson($this->url('options'))->assertOk()->assertJsonCount(0, 'data.supplier_invoices');
        $viewer->givePermissionTo('view-supplier-payables');
        $this->getJson($this->url('supplier-invoices'))->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/approve'))->assertForbidden();
        $other = User::factory()->mdaUser($this->otherMda)->create();
        $other->givePermissionTo(['view-supplier-payables', 'manage-supplier-invoices', 'approve-supplier-invoices', 'record-supplier-payments', 'approve-supplier-payments']);
        $this->actingAs($other)->getJson($this->url('supplier-invoices'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url('supplier-payments/'.$payment))->assertNotFound();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/cancel'), ['reason' => 'Foreign'])->assertNotFound();
        $this->postJson($this->url('supplier-payments/'.$payment.'/approve'))->assertNotFound();
        $this->getJson($this->url('options'))->assertOk()->assertJsonCount(0, 'data.supplier_invoices')->assertJsonCount(0, 'data.cycles');
        $this->getJson($this->url('options').'?mda_id='.$this->mda->id)->assertForbidden();
    }

    public function test_unposted_receipts_and_foreign_order_items_cannot_be_matched(): void
    {
        $ids = $this->workflow();
        $invoice = $this->supplierInvoice($ids);
        $draft = $this->postJson($this->url('purchase-orders/'.$ids['po'].'/receipts'))->assertCreated()->json('data.id');
        $item = PurchaseOrder::query()->findOrFail($ids['po'])->items()->firstOrFail();
        $line = $this->postJson($this->url('receipts/'.$draft.'/items'), ['purchase_order_item_id' => $item->id, 'quantity_received' => 1, 'quantity_accepted' => 1])->assertCreated()->json('data.id');
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/items'), ['procurement_receipt_item_id' => $line, 'quantity' => 1])->assertNotFound();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/items'), ['procurement_receipt_item_id' => 999999, 'quantity' => 1])->assertUnprocessable();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/remove-item'), ['item_id' => 999999])->assertNotFound();
    }

    public function test_invoice_search_payment_status_and_overdue_filters_use_actual_settlement(): void
    {
        $ids = $this->workflow();
        $invoice = $this->supplierInvoice($ids, true);
        SupplierInvoice::query()->findOrFail($invoice)->update(['due_date' => today()->subDay()]);
        $this->getJson($this->url('supplier-invoices').'?payment_status=unpaid&overdue=1&search=INV-TEST')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.overdue', true);
        $this->getJson($this->url('supplier-invoices').'?payment_status=paid')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url('supplier-invoices').'?payment_status=partially_paid')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url('supplier-invoices').'?search=NONEXISTENT')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url('supplier-payments').'?supplier_invoice_id='.$invoice)->assertOk()->assertJsonCount(0, 'data');
    }

    private function supplierInvoice(array $ids, bool $approve = false, int $total = 200): int
    {
        $invoice = $this->postJson($this->url('supplier-invoices'), ['mda_id' => $this->mda->id, 'purchase_order_id' => $ids['po'], 'invoice_no' => 'INV-TEST', 'invoice_date' => today()->toDateString(), 'total_amount' => $total])->assertCreated()->json('data.id');
        $line = ProcurementReceiptItem::query()->where('procurement_receipt_id', $ids['receipt'])->firstOrFail();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/items'), ['procurement_receipt_item_id' => $line->id, 'quantity' => 2])->assertCreated();
        if ($approve) {
            $this->postJson($this->url('supplier-invoices/'.$invoice.'/submit'))->assertOk();
            $this->postJson($this->url('supplier-invoices/'.$invoice.'/approve'))->assertOk();
        }

        return $invoice;
    }

    public function test_draft_invoice_header_corrections_are_audited_and_preserve_date_consistency(): void
    {
        $ids = $this->workflow();
        $invoice = $this->supplierInvoice($ids);
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/update'), [
            'invoice_no' => 'inv-corrected', 'invoice_date' => today()->subDays(2)->toDateString(), 'due_date' => today()->subDay()->toDateString(), 'total_amount' => 200, 'reason' => 'Corrected supplier header',
        ])->assertOk()->assertJsonPath('data.invoice_no', 'INV-CORRECTED');
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/update'), [
            'invoice_date' => today()->toDateString(), 'total_amount' => 200, 'reason' => 'Invalid date pair',
        ])->assertUnprocessable();
        $this->getJson($this->url('supplier-invoices/'.$invoice))->assertOk()->assertJsonPath('data.history.0.reason', 'Corrected supplier header');
    }

    public function test_fractional_payments_are_exact_and_finance_dates_use_the_lagos_calendar(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 23:30:00', 'UTC'));
        $ids = $this->workflow();
        $invoice = $this->postJson($this->url('supplier-invoices'), ['mda_id' => $this->mda->id, 'purchase_order_id' => $ids['po'], 'invoice_no' => 'INV-CENTS', 'invoice_date' => '2026-10-19', 'due_date' => '2026-10-19', 'total_amount' => '0.30'])->assertCreated()->json('data.id');
        $receiptItem = ProcurementReceiptItem::query()->where('procurement_receipt_id', $ids['receipt'])->firstOrFail();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/items'), ['procurement_receipt_item_id' => $receiptItem->id, 'quantity' => '0.0030'])->assertCreated()->assertJsonPath('data.total_amount', '0.30');
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/submit'))->assertOk();
        $this->postJson($this->url('supplier-invoices/'.$invoice.'/approve'))->assertOk();
        $approver = User::factory()->mdaUser($this->mda)->create();
        $approver->givePermissionTo('approve-supplier-payments');
        foreach (['0.10', '0.20'] as $amount) {
            $payment = $this->supplierPayment($invoice, $amount);
            $this->postJson($this->url('supplier-payments/'.$payment.'/submit'))->assertOk();
            $this->actingAs($approver)->postJson($this->url('supplier-payments/'.$payment.'/approve'))->assertOk();
            $this->actingAs($this->officer)->postJson($this->url('supplier-payments/'.$payment.'/mark-paid'), ['payment_reference' => 'CENTS-'.$payment, 'paid_date' => '2026-10-19'])->assertOk();
        }
        $this->getJson($this->url('supplier-invoices/'.$invoice))->assertOk()->assertJsonPath('data.paid_amount', '0.30')->assertJsonPath('data.outstanding_amount', '0.00')->assertJsonPath('data.overdue', false);
        $this->travelBack();
    }

    public function test_accepted_deliveries_on_cancelled_orders_remain_invoiceable_without_new_expenditure(): void
    {
        $ids = $this->workflow();
        $this->postJson($this->url('purchase-orders/'.$ids['po'].'/cancel'), ['reason' => 'No remaining deliveries'])->assertOk();
        $count = BudgetTransaction::query()->count();
        $invoice = $this->supplierInvoice($ids, true);
        $this->getJson($this->url('supplier-invoices/'.$invoice))->assertOk()->assertJsonPath('data.outstanding_amount', '200.00');
        $this->assertSame($count, BudgetTransaction::query()->count());
    }

    private function supplierPayment(int $invoice, string|int $amount): int
    {
        return $this->postJson($this->url('supplier-invoices/'.$invoice.'/payments'), ['request_key' => (string) Str::uuid(), 'method' => 'bank_transfer', 'amount' => $amount])->assertCreated()->json('data.id');
    }

    private function draftRequest(): array
    {
        $allocation = BudgetAllocation::query()->where('mda_id', $this->mda->id)->first() ?? $this->allocation($this->mda);
        $this->actingAs($this->officer);
        $request = $this->postJson($this->url('requisitions'), ['mda_id' => $this->mda->id, 'title' => 'Request', 'budget_cycle_id' => $allocation->budget_cycle_id])->assertCreated()->json('data.id');
        $line = $this->postJson($this->url('requisitions/'.$request.'/lines'), ['item_description' => 'Equipment', 'quantity' => 4, 'estimated_amount' => 400, 'budget_allocation_id' => $allocation->id])->assertCreated()->json('data.id');

        return [$request, $line];
    }

    private function workflow(): array
    {
        $this->actingAs($this->officer);
        $ids['cycle'] = $this->postJson($this->url('cycles'), ['mda_id' => $this->mda->id, 'year' => 2026, 'name' => 'Annual budget'])->assertCreated()->json('data.id');
        $this->postJson($this->url('cycles/'.$ids['cycle'].'/approve'))->assertUnprocessable();
        $this->postJson($this->url('cycles/'.$ids['cycle'].'/submit'))->assertOk();
        $this->postJson($this->url('cycles/'.$ids['cycle'].'/approve'))->assertOk()->assertJsonPath('data.status', 'approved');
        $account = $this->postJson($this->url('accounts'), ['mda_id' => $this->mda->id, 'code' => 'CAPITAL', 'name' => 'Equipment', 'account_type' => 'capital'])->assertCreated()->json('data.id');
        $ids['allocation'] = $this->postJson($this->url('cycles/'.$ids['cycle'].'/allocations'), ['budget_account_id' => $account, 'title' => 'Equipment', 'approved_amount' => 1000])->assertCreated()->json('data.id');
        $this->postJson($this->url('allocations/'.$ids['allocation'].'/approve'))->assertOk();
        $ids['release'] = $this->postJson($this->url('allocations/'.$ids['allocation'].'/releases'), ['amount' => 800, 'release_date' => '2026-10-15'])->assertCreated()->json('data.id');
        $this->postJson($this->url('releases/'.$ids['release'].'/post'))->assertOk();
        $ids['adjustment'] = $this->postJson($this->url('allocations/'.$ids['allocation'].'/adjustments'), ['adjustment_type' => 'increase', 'amount' => 100, 'reason' => 'Supplementary'])->assertCreated()->json('data.id');
        $this->postJson($this->url('adjustments/'.$ids['adjustment'].'/approve'))->assertOk();
        $ids['plan'] = $this->postJson($this->url('plans'), ['mda_id' => $this->mda->id, 'year' => 2026, 'title' => 'Procurement', 'budget_cycle_id' => $ids['cycle']])->assertCreated()->json('data.id');
        $this->postJson($this->url('plans/'.$ids['plan'].'/import-allocations'))->assertOk()->assertJsonPath('data.lines_synced', 1);
        $planLine = $this->getJson($this->url('plans/'.$ids['plan']))->assertOk()->json('data.lines.0.id');
        $ids['requisition'] = $this->postJson($this->url('requisitions'), ['mda_id' => $this->mda->id, 'title' => 'Equipment request', 'procurement_plan_id' => $ids['plan']])->assertCreated()->json('data.id');
        $this->postJson($this->url('requisitions/'.$ids['requisition'].'/lines'), [
            'procurement_plan_line_id' => $planLine, 'item_description' => 'Equipment', 'quantity' => 4, 'estimated_amount' => 400,
        ])->assertCreated();
        $this->postJson($this->url('requisitions/'.$ids['requisition'].'/submit'))->assertOk();
        $this->postJson($this->url('requisitions/'.$ids['requisition'].'/review-budget'))->assertOk()->assertJsonPath('data.status', 'available');
        $this->postJson($this->url('requisitions/'.$ids['requisition'].'/approve'))->assertOk();
        $ids['vendor'] = $this->postJson($this->url('vendors'), ['mda_id' => $this->mda->id, 'name' => 'Supplier'])->assertCreated()->json('data.id');
        $ids['solicitation'] = $this->postJson($this->url('requisitions/'.$ids['requisition'].'/solicitations'), ['procurement_method' => 'rfq'])->assertCreated()->json('data.id');
        $this->postJson($this->url('solicitations/'.$ids['solicitation'].'/publish'))->assertOk();
        $ids['bid'] = $this->postJson($this->url('solicitations/'.$ids['solicitation'].'/bids'), ['vendor_id' => $ids['vendor'], 'quoted_amount' => 400])->assertCreated()->json('data.id');
        $this->postJson($this->url('solicitations/'.$ids['solicitation'].'/close'))->assertOk();
        $ids['evaluation'] = $this->postJson($this->url('bids/'.$ids['bid'].'/evaluate'), ['technical_score' => 45, 'financial_score' => 40])->assertOk()->json('data.id');
        $ids['award'] = $this->postJson($this->url('evaluations/'.$ids['evaluation'].'/recommend'), ['summary' => 'Recommended'])->assertOk()->json('data.id');
        $this->postJson($this->url('awards/'.$ids['award'].'/approve'))->assertOk();
        $ids['po'] = $this->postJson($this->url('awards/'.$ids['award'].'/purchase-order'))->assertCreated()->json('data.id');
        $poItem = $this->getJson($this->url('purchase-orders/'.$ids['po']))->assertOk()->json('data.items.0.id');
        $ids['receipt'] = $this->postJson($this->url('purchase-orders/'.$ids['po'].'/receipts'))->assertCreated()->json('data.id');
        $this->postJson($this->url('receipts/'.$ids['receipt'].'/items'), ['purchase_order_item_id' => $poItem, 'quantity_received' => 2, 'quantity_accepted' => 2])->assertCreated();
        $this->postJson($this->url('receipts/'.$ids['receipt'].'/post'))->assertOk();
        $ids['document'] = $this->postJson($this->url('vendors/'.$ids['vendor'].'/documents'), [
            'title' => 'Registration', 'file' => UploadedFile::fake()->create('registration.pdf', 10, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        return $ids;
    }

    private function allocation(Mda $mda): BudgetAllocation
    {
        $cycle = BudgetCycle::query()->create(['mda_id' => $mda->id, 'year' => 2026, 'status' => 'approved']);
        $account = BudgetAccount::query()->create(['mda_id' => $mda->id, 'code' => 'CAP', 'name' => 'Capital', 'account_type' => 'capital']);
        $allocation = BudgetAllocation::query()->create([
            'mda_id' => $mda->id, 'budget_cycle_id' => $cycle->id, 'budget_account_id' => $account->id,
            'title' => 'Equipment', 'approved_amount' => 1000,
        ]);

        return app(BudgetLedgerService::class)->approveAllocation($allocation, $this->officer);
    }

    private function url(string $path): string
    {
        return '/api/budget-procurement/'.$path;
    }
}
