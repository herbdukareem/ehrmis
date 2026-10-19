<?php

namespace App\Http\Controllers\Api;

use App\Domain\Procurement\Models\ProcurementReceiptItem;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Procurement\Models\SupplierInvoiceItem;
use App\Domain\Procurement\Models\SupplierPayment;
use App\Domain\Procurement\Services\SupplierPayablesService;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierPayablesController extends BudgetProcurementController
{
    public function invoices(Request $request): JsonResponse
    {
        $query = SupplierInvoice::query()->with(['vendor', 'purchaseOrder'])->withBalances();
        $data = $request->validate(['payment_status' => ['nullable', Rule::in(['unpaid', 'partially_paid', 'paid'])], 'overdue' => ['nullable', 'boolean']]);
        if (! empty($data['payment_status'])) {
            $query->whereNotIn('status', ['cancelled', 'rejected']);
            $paid = SupplierPayment::query()->selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('supplier_invoice_id', 'supplier_invoices.id')->whereColumn('mda_id', 'supplier_invoices.mda_id')->where('status', 'paid');
            $query->where(function ($q) use ($paid, $data) {
                match ($data['payment_status']) {
                    'unpaid' => $q->where($paid, '=', 0),
                    'paid' => $q->where($paid, '>=', DB::raw('supplier_invoices.total_amount')),
                    'partially_paid' => $q->where($paid, '>', 0)->where($paid, '<', DB::raw('supplier_invoices.total_amount')),
                };
            });
        }
        if ($request->boolean('overdue')) {
            $query->where('status', 'approved')->whereDate('due_date', '<', now('Africa/Lagos')->toDateString())->where(function ($q) {
                $q->where(SupplierPayment::query()->selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('supplier_invoice_id', 'supplier_invoices.id')->whereColumn('mda_id', 'supplier_invoices.mda_id')->where('status', 'paid'), '<', DB::raw('supplier_invoices.total_amount'));
            });
        }

        return $this->listing($request, $query, ['vendor_id' => ['nullable', 'integer', 'min:1'], 'purchase_order_id' => ['nullable', 'integer', 'min:1']]);
    }

    public function showInvoice(Request $request, SupplierInvoice $invoice): JsonResponse
    {
        $this->assertMda($request, $invoice->mda_id);
        $invoice->load(['vendor', 'purchaseOrder', 'items.receiptItem.receipt', 'payments'])->loadSum('items as matched_total', 'total_amount');
        $receipts = ProcurementReceiptItem::query()->where('mda_id', $invoice->mda_id)
            ->whereHas('receipt', fn ($q) => $q->where('purchase_order_id', $invoice->purchase_order_id)->where('status', 'posted'))
            ->where('quantity_accepted', '>', 0)->with('receipt')->get()->map(function ($row) use ($invoice) {
                $billed = SupplierInvoiceItem::query()->where('mda_id', $invoice->mda_id)->where('procurement_receipt_item_id', $row->id)->where('supplier_invoice_id', '!=', $invoice->id)
                    ->whereHas('invoice', fn ($q) => $q->whereNotIn('status', ['rejected', 'cancelled']))->get();
                $quantity = $billed->reduce(fn ($total, $item) => $total->plus($item->quantity), BigDecimal::zero());

                return ['id' => $row->id, 'receipt_no' => $row->receipt->receipt_no, 'item_description' => $row->item_description,
                    'quantity_accepted' => $row->quantity_accepted, 'available_quantity' => (string) BigDecimal::of($row->quantity_accepted)->minus($quantity)->toScale(4), 'unit_price' => $row->unit_price];
            });
        $invoice->setAttribute('matching_receipts', $receipts);

        return $this->procurementDetail($invoice);
    }

    public function storeInvoice(Request $request, SupplierPayablesService $service): JsonResponse
    {
        $mdaId = $this->selectedMda($request);
        $data = $request->validate([
            'purchase_order_id' => ['required', 'integer', $this->reference(PurchaseOrder::class, $mdaId)],
            'invoice_no' => ['required', 'string', 'max:120'],
            'invoice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Lagos')->toDateString()],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'total_amount' => $this->amountRules(), 'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        return $this->uniqueResult(fn () => $service->createInvoice(PurchaseOrder::query()->findOrFail($data['purchase_order_id']), $data, $request->user()), 201);
    }

    public function updateInvoice(Request $request, SupplierInvoice $invoice, SupplierPayablesService $service): JsonResponse
    {
        $this->assertMda($request, $invoice->mda_id);
        $data = $request->validate([
            'invoice_no' => ['sometimes', 'required', 'string', 'max:120'],
            'invoice_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Lagos')->toDateString()],
            'total_amount' => $this->amountRules(), 'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.($request->input('invoice_date') ?: $invoice->invoice_date->toDateString())],
            'notes' => ['nullable', 'string', 'max:10000'], ...$this->reasonRules(),
        ]);

        return $this->uniqueResult(fn () => $service->updateInvoice($invoice, $data, $request->user()));
    }

    public function storeItem(Request $request, SupplierInvoice $invoice, SupplierPayablesService $service): JsonResponse
    {
        $this->assertMda($request, $invoice->mda_id);
        $data = $request->validate([
            'procurement_receipt_item_id' => ['required', 'integer', $this->reference(ProcurementReceiptItem::class, $invoice->mda_id)],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:9999999999'],
            'unit_price' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999.99'],
        ]);

        return $this->result($service->matchItem($invoice, $data, $request->user()), 201);
    }

    public function removeItem(Request $request, SupplierInvoice $invoice, SupplierPayablesService $service): JsonResponse
    {
        $this->assertMda($request, $invoice->mda_id);
        $data = $request->validate(['item_id' => ['required', 'integer', 'min:1']]);

        return $this->result($service->removeItem($invoice, $data['item_id'], $request->user()));
    }

    public function invoiceAction(Request $request, SupplierInvoice $invoice, SupplierPayablesService $service): JsonResponse
    {
        $this->assertMda($request, $invoice->mda_id);
        $action = $request->route()->defaults['payablesAction'];
        $data = $request->validate(in_array($action, ['return', 'reject', 'cancel'], true) ? $this->reasonRules() : []);

        return $this->result($service->invoiceAction($invoice, $action, $data, $request->user()));
    }

    public function payments(Request $request): JsonResponse
    {
        return $this->listing($request, SupplierPayment::query()->with(['vendor', 'invoice']), ['supplier_invoice_id' => ['nullable', 'integer', 'min:1'], 'vendor_id' => ['nullable', 'integer', 'min:1']]);
    }

    public function showPayment(Request $request, SupplierPayment $payment): JsonResponse
    {
        $this->assertMda($request, $payment->mda_id);

        return $this->procurementDetail($payment->load(['vendor', 'invoice.purchaseOrder']));
    }

    public function storePayment(Request $request, SupplierInvoice $invoice, SupplierPayablesService $service): JsonResponse
    {
        $this->assertMda($request, $invoice->mda_id);
        $data = $request->validate(['amount' => $this->amountRules(), 'method' => ['required', Rule::in(['bank_transfer', 'cash', 'cheque', 'other'])], 'request_key' => ['required', 'uuid'], 'notes' => ['nullable', 'string', 'max:10000']]);

        return $this->uniqueResult(fn () => $service->createPayment($invoice, $data, $request->user()), 201);
    }

    public function paymentAction(Request $request, SupplierPayment $payment, SupplierPayablesService $service): JsonResponse
    {
        $this->assertMda($request, $payment->mda_id);
        $action = $request->route()->defaults['payablesAction'];
        $data = $request->validate(match ($action) {
            'mark-paid' => ['payment_reference' => ['required', 'string', 'max:160'], 'paid_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Lagos')->toDateString()]],
            'reject', 'cancel' => $this->reasonRules(), default => [],
        });

        return $this->uniqueResult(fn () => $service->paymentAction($payment, $action, $data, $request->user()));
    }

    private function amountRules(): array
    {
        return ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999.99'];
    }

    private function reasonRules(): array
    {
        return ['reason' => ['required', 'string', 'max:5000']];
    }

    private function uniqueResult(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return $this->result($operation(), $status);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => 'This invoice number, payment request or payment reference has already been recorded. Refresh before retrying.']);
        }
    }
}
