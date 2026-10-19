<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Procurement\Models\ProcurementReceiptItem;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Procurement\Models\SupplierInvoiceItem;
use App\Domain\Procurement\Models\SupplierPayment;
use App\Models\User;
use App\Services\AuditLogService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierPayablesService
{
    public function __construct(private AuditLogService $audit) {}

    public function createInvoice(PurchaseOrder $order, array $data, User $actor): SupplierInvoice
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->ensure(in_array($order->status, ['issued', 'partially_received', 'received', 'closed', 'cancelled'], true), 'This order cannot be invoiced.');
            $number = Str::upper(trim($data['invoice_no']));
            $this->ensure(! SupplierInvoice::query()->where('mda_id', $order->mda_id)->where('vendor_id', $order->vendor_id)->where('invoice_no', $number)->exists(), 'This supplier invoice number is already recorded.');
            $invoice = SupplierInvoice::query()->create([
                'mda_id' => $order->mda_id, 'purchase_order_id' => $order->id, 'vendor_id' => $order->vendor_id,
                'currency' => $order->currency, 'invoice_no' => $number, 'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null, 'total_amount' => $data['total_amount'], 'notes' => $data['notes'] ?? null,
                'status' => 'draft', 'prepared_by' => $actor->id, 'prepared_at' => now(),
            ]);
            $this->log($invoice, 'created', [], [], $actor);

            return $invoice->fresh();
        });
    }

    public function updateInvoice(SupplierInvoice $invoice, array $data, User $actor): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = $this->lockInvoice($invoice);
            $this->stage($invoice, ['draft', 'returned']);
            $before = $invoice->load('items')->toArray();
            if (isset($data['invoice_no'])) {
                $data['invoice_no'] = Str::upper(trim($data['invoice_no']));
                $this->ensure(! SupplierInvoice::query()->where('mda_id', $invoice->mda_id)->where('vendor_id', $invoice->vendor_id)->where('invoice_no', $data['invoice_no'])->whereKeyNot($invoice->id)->exists(), 'This supplier invoice number is already recorded.');
            }
            $invoice->fill(array_intersect_key($data, array_flip(['invoice_no', 'invoice_date', 'total_amount', 'due_date', 'notes'])));
            $this->ensure(! $invoice->due_date || $invoice->due_date->toDateString() >= $invoice->invoice_date->toDateString(), 'Due date cannot precede the invoice date.');
            $invoice->save();
            $this->ensure(BigDecimal::of($invoice->matched_amount)->isLessThanOrEqualTo($invoice->total_amount), 'The declared total is less than the matched invoice items.');
            $this->log($invoice, 'updated', $before, $data, $actor);

            return $invoice->fresh();
        });
    }

    public function matchItem(SupplierInvoice $invoice, array $data, User $actor): SupplierInvoiceItem
    {
        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = $this->lockInvoice($invoice);
            $this->stage($invoice, ['draft', 'returned']);
            $before = $invoice->load('items')->toArray();
            $receipt = ProcurementReceiptItem::query()->where('mda_id', $invoice->mda_id)->whereKey($data['procurement_receipt_item_id'])
                ->whereHas('receipt', fn ($q) => $q->where('purchase_order_id', $invoice->purchase_order_id)->where('status', 'posted'))
                ->with(['receipt', 'purchaseOrderItem'])->lockForUpdate()->firstOrFail();
            $item = $invoice->items()->firstOrNew(['procurement_receipt_item_id' => $receipt->id]);
            $quantity = BigDecimal::of((string) $data['quantity'])->toScale(4);
            $price = BigDecimal::of((string) ($data['unit_price'] ?? $receipt->unit_price))->toScale(2);
            $this->ensure($quantity->isPositive() && $price->isPositive(), 'Invoice quantity and price must be greater than zero.');
            $this->ensure($price->isLessThanOrEqualTo($receipt->unit_price) && $price->isLessThanOrEqualTo($receipt->purchaseOrderItem->unit_price), 'The invoiced price exceeds the order or accepted receipt price.');
            $other = SupplierInvoiceItem::query()->where('mda_id', $invoice->mda_id)->where('procurement_receipt_item_id', $receipt->id)
                ->whereHas('invoice', fn ($q) => $q->whereNotIn('status', ['rejected', 'cancelled']))
                ->when($item->exists, fn ($q) => $q->whereKeyNot($item->id))->get();
            $usedQuantity = $other->reduce(fn ($total, $row) => $total->plus($row->quantity), BigDecimal::zero());
            $usedAmount = $other->reduce(fn ($total, $row) => $total->plus($row->total_amount), BigDecimal::zero());
            $total = $quantity->multipliedBy($price)->toScale(2, RoundingMode::HALF_UP);
            $this->ensure($usedQuantity->plus($quantity)->isLessThanOrEqualTo($receipt->quantity_accepted), 'This would invoice quantities already billed or not accepted.');
            $this->ensure($usedAmount->plus($total)->isLessThanOrEqualTo($receipt->accepted_amount), 'This would exceed the accepted receipt value.');
            $item->fill(['mda_id' => $invoice->mda_id, 'purchase_order_item_id' => $receipt->purchase_order_item_id,
                'item_description' => $receipt->item_description, 'quantity' => (string) $quantity,
                'unit_price' => (string) $price, 'total_amount' => (string) $total])->save();
            $this->ensure(BigDecimal::of($invoice->matched_amount)->isLessThanOrEqualTo($invoice->total_amount), 'Matched items exceed the supplier invoice total.');
            $this->log($invoice, 'item_matched', $before, $data, $actor);

            return $item->fresh();
        });
    }

    public function removeItem(SupplierInvoice $invoice, int $itemId, User $actor): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $itemId, $actor) {
            $invoice = $this->lockInvoice($invoice);
            $this->stage($invoice, ['draft', 'returned']);
            $before = $invoice->load('items')->toArray();
            $invoice->items()->whereKey($itemId)->firstOrFail()->delete();
            $this->log($invoice, 'item_removed', $before, [], $actor);

            return $invoice->fresh();
        });
    }

    public function invoiceAction(SupplierInvoice $invoice, string $action, array $data, User $actor): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $action, $data, $actor) {
            $invoice = $this->lockInvoice($invoice);
            $target = ['submit' => 'submitted', 'approve' => 'approved', 'return' => 'returned', 'reject' => 'rejected', 'cancel' => 'cancelled'][$action];
            if ($invoice->status === $target) {
                return $invoice;
            }
            $this->stage($invoice, match ($action) {
                'submit' => ['draft', 'returned'], 'cancel' => ['draft', 'returned', 'submitted', 'approved'], default => ['submitted']
            });
            $before = $invoice->load('items')->toArray();
            if (in_array($action, ['submit', 'approve'], true)) {
                $this->ensure($invoice->items()->exists() && BigDecimal::of($invoice->matched_amount)->isEqualTo($invoice->total_amount), 'Match the full invoice total to posted, accepted receipt items first.');
                foreach ($invoice->items()->with('receiptItem.receipt')->get() as $item) {
                    $this->ensure($item->receiptItem->receipt->status === 'posted' && (int) $item->receiptItem->receipt->purchase_order_id === (int) $invoice->purchase_order_id, 'A matched receipt is no longer eligible.');
                }
            }
            if ($action === 'cancel') {
                $this->ensure(! $invoice->payments()->whereNotIn('status', ['rejected', 'cancelled'])->exists(), 'Cancel outstanding payment records first. Paid invoices cannot be cancelled.');
            }
            $prefix = ['submit' => 'submitted', 'approve' => 'approved', 'return' => null, 'reject' => 'rejected', 'cancel' => 'cancelled'][$action];
            $invoice->status = $target;
            if ($prefix) {
                $invoice->setAttribute($prefix.'_by', $actor->id)->setAttribute($prefix.'_at', now());
            }
            if ($action === 'reject') {
                $invoice->rejection_reason = $data['reason'];
            }
            if ($action === 'cancel') {
                $invoice->cancellation_reason = $data['reason'];
            }
            $invoice->save();
            $this->log($invoice, $action, $before, $data, $actor);

            return $invoice->fresh();
        });
    }

    public function createPayment(SupplierInvoice $invoice, array $data, User $actor): SupplierPayment
    {
        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $existing = SupplierPayment::query()->where('mda_id', $invoice->mda_id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                $this->ensure((int) $existing->supplier_invoice_id === (int) $invoice->id && BigDecimal::of($existing->amount)->isEqualTo((string) $data['amount']) && $existing->method === $data['method'], 'This request key belongs to a different payment.');

                return $existing;
            }
            $this->stage($invoice, ['approved']);
            $this->ensure(BigDecimal::of((string) $data['amount'])->isPositive() && BigDecimal::of((string) $data['amount'])->isLessThanOrEqualTo($invoice->available_for_payment), 'This payment exceeds the unreserved outstanding invoice balance.');
            $payment = $invoice->payments()->create([
                'mda_id' => $invoice->mda_id, 'vendor_id' => $invoice->vendor_id, 'currency' => $invoice->currency,
                'request_key' => $data['request_key'], 'payment_no' => 'PAY-'.now()->format('Y').'-'.Str::upper(Str::random(12)),
                'amount' => $data['amount'], 'method' => $data['method'], 'notes' => $data['notes'] ?? null,
                'status' => 'draft', 'prepared_by' => $actor->id, 'prepared_at' => now(),
            ]);
            $this->log($payment, 'created', [], [], $actor);

            return $payment->fresh();
        });
    }

    public function paymentAction(SupplierPayment $payment, string $action, array $data, User $actor): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $action, $data, $actor) {
            // Invoice-level serialization protects balances across concurrent partial payments.
            $invoice = SupplierInvoice::query()->whereKey($payment->supplier_invoice_id)->lockForUpdate()->firstOrFail();
            $payment = SupplierPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $target = ['submit' => 'submitted', 'approve' => 'approved', 'mark-paid' => 'paid', 'reject' => 'rejected', 'cancel' => 'cancelled'][$action];
            if ($payment->status === $target) {
                if ($action === 'mark-paid') {
                    $this->ensure($payment->payment_reference === Str::upper(trim($data['payment_reference'])) && $payment->paid_date->toDateString() === $data['paid_date'], 'This payment is already recorded with different settlement details.');
                }

                return $payment;
            }
            $this->stage($payment, match ($action) {
                'submit' => ['draft'], 'approve', 'reject' => ['submitted'], 'mark-paid' => ['approved'], 'cancel' => ['draft', 'submitted', 'approved']
            });
            $before = $payment->toArray();
            if (in_array($action, ['submit', 'approve', 'mark-paid'], true)) {
                $this->stage($invoice, ['approved']);
            }
            if ($action === 'submit') {
                $this->ensure(BigDecimal::of($payment->amount)->isLessThanOrEqualTo($invoice->available_for_payment), 'This payment exceeds the unreserved outstanding balance.');
            }
            if ($action === 'approve') {
                $this->ensure((int) $payment->prepared_by !== (int) $actor->id, 'A different authorized officer must approve this payment.');
                $this->ensure(BigDecimal::of($payment->amount)->isLessThanOrEqualTo($invoice->outstanding_amount), 'This payment exceeds the outstanding balance.');
            }
            if ($action === 'mark-paid') {
                $this->ensure(BigDecimal::of($payment->amount)->isLessThanOrEqualTo($invoice->outstanding_amount), 'This payment exceeds the outstanding balance.');
                $reference = Str::upper(trim($data['payment_reference']));
                $this->ensure(! SupplierPayment::query()->where('mda_id', $payment->mda_id)->where('payment_reference', $reference)->whereKeyNot($payment->id)->exists(), 'This payment reference is already recorded.');
                $this->ensure($data['paid_date'] >= $invoice->invoice_date->toDateString(), 'Payment date cannot precede the invoice date.');
                $payment->payment_reference = $reference;
                $payment->paid_date = $data['paid_date'];
            }
            $prefix = ['submit' => 'submitted', 'approve' => 'approved', 'mark-paid' => 'paid', 'reject' => 'rejected', 'cancel' => 'cancelled'][$action];
            $payment->status = $target;
            $payment->setAttribute($prefix.'_by', $actor->id)->setAttribute($prefix.'_at', now());
            if ($action === 'reject') {
                $payment->rejection_reason = $data['reason'];
            }
            if ($action === 'cancel') {
                $payment->cancellation_reason = $data['reason'];
            }
            $payment->save();
            // Receipt posting already recognized expenditure. Settlement never posts to the budget ledger.
            $this->log($payment, $action, $before, $data, $actor);

            return $payment->fresh();
        });
    }

    private function lockInvoice(SupplierInvoice $invoice): SupplierInvoice
    {
        PurchaseOrder::query()->whereKey($invoice->purchase_order_id)->lockForUpdate()->firstOrFail();

        return SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
    }

    private function log(Model $record, string $action, array $before, array $data, User $actor): void
    {
        $relations = $record instanceof SupplierInvoice ? ['items'] : [];
        $this->audit->log('procurement.payables.'.$action, $record, $before, $record->fresh($relations)->toArray(), [
            'mda_id' => $record->mda_id, 'action' => $action, 'actor_id' => $actor->id, 'reason' => $data['reason'] ?? null,
        ]);
    }

    private function stage(Model $record, array $statuses): void
    {
        $this->ensure(in_array($record->status, $statuses, true), 'This action is not allowed while the record is '.$record->status.'.');
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['payables' => $message]);
        }
    }
}
