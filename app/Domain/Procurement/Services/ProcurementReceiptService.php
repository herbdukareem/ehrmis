<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Budget\Services\BudgetLedgerService;
use App\Domain\Procurement\Models\ProcurementReceipt;
use App\Domain\Procurement\Models\ProcurementReceiptItem;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcurementReceiptService
{
    public function __construct(
        private BudgetLedgerService $budgetLedgerService,
        private AuditLogService $auditLogService,
    ) {}

    public function createReceipt(PurchaseOrder $purchaseOrder, array $data = [], ?User $actor = null): ProcurementReceipt
    {
        return DB::transaction(function () use ($purchaseOrder, $data, $actor): ProcurementReceipt {
            $purchaseOrder = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();

            if (! in_array($purchaseOrder->status, ['issued', 'partially_received', 'received'], true)) {
                throw ValidationException::withMessages(['purchase_order' => 'Only issued purchase orders can receive goods or services.']);
            }

            $receipt = ProcurementReceipt::query()->create([
                'mda_id' => $purchaseOrder->mda_id,
                'purchase_order_id' => $purchaseOrder->id,
                'vendor_id' => $purchaseOrder->vendor_id,
                'receipt_no' => $data['receipt_no'] ?? $this->nextReceiptNumber($purchaseOrder->mda_id),
                'received_date' => $data['received_date'] ?? now()->toDateString(),
                'delivery_note_no' => $data['delivery_note_no'] ?? null,
                'inspection_status' => $data['inspection_status'] ?? 'pending',
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'received_by' => $actor?->id,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $this->auditLogService->log('procurement.receipt_created', $receipt, [], $receipt->toArray(), [
                'mda_id' => $receipt->mda_id,
                'purchase_order_id' => $purchaseOrder->id,
            ]);

            return $receipt->fresh(['purchaseOrder', 'items']);
        });
    }

    public function addItem(ProcurementReceipt $receipt, PurchaseOrderItem $purchaseOrderItem, array $data = [], ?User $actor = null): ProcurementReceiptItem
    {
        return DB::transaction(function () use ($receipt, $purchaseOrderItem, $data, $actor): ProcurementReceiptItem {
            $this->lockOpenOrder($receipt);
            $receipt = ProcurementReceipt::query()->with('purchaseOrder')->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $purchaseOrderItem = PurchaseOrderItem::query()->whereKey($purchaseOrderItem->id)->lockForUpdate()->firstOrFail();

            $this->assertDraft($receipt);
            $this->assertReceiptItemBelongsToPurchaseOrder($receipt, $purchaseOrderItem);

            $line = ProcurementReceiptItem::query()->firstOrNew([
                'procurement_receipt_id' => $receipt->id,
                'purchase_order_item_id' => $purchaseOrderItem->id,
            ]);
            $before = $line->exists ? $line->toArray() : [];

            $quantityReceived = round((float) ($data['quantity_received'] ?? $line->quantity_received ?? 0), 4);
            $quantityAccepted = round((float) ($data['quantity_accepted'] ?? $quantityReceived), 4);
            $quantityRejected = round((float) ($data['quantity_rejected'] ?? max(0, $quantityReceived - $quantityAccepted)), 4);

            if ($quantityReceived <= 0) {
                throw ValidationException::withMessages(['quantity_received' => 'Received quantity must be greater than zero.']);
            }

            if ($quantityAccepted < 0 || $quantityRejected < 0 || round($quantityAccepted + $quantityRejected, 4) > $quantityReceived) {
                throw ValidationException::withMessages(['quantity_accepted' => 'Accepted and rejected quantities cannot exceed received quantity.']);
            }

            $unitPrice = $purchaseOrderItem->unit_price !== null
                ? (float) $purchaseOrderItem->unit_price
                : $this->unitPriceFromTotal($purchaseOrderItem);
            $receivedAmount = round((float) ($data['received_amount'] ?? ($unitPrice * $quantityReceived)), 2);
            $acceptedAmount = round((float) ($data['accepted_amount'] ?? ($unitPrice * $quantityAccepted)), 2);

            $this->assertWithinRemainingOrder($purchaseOrderItem, $quantityAccepted, $acceptedAmount, $line->exists ? $line : null);

            $line->fill([
                'mda_id' => $receipt->mda_id,
                'budget_allocation_id' => $purchaseOrderItem->budget_allocation_id,
                'budget_commitment_id' => $purchaseOrderItem->budget_commitment_id,
                'item_description' => $data['item_description'] ?? $purchaseOrderItem->item_description,
                'quantity_received' => $quantityReceived,
                'quantity_accepted' => $quantityAccepted,
                'quantity_rejected' => $quantityRejected,
                'unit' => $data['unit'] ?? $purchaseOrderItem->unit,
                'unit_price' => $unitPrice,
                'received_amount' => $receivedAmount,
                'accepted_amount' => $acceptedAmount,
                'status' => $this->receiptLineStatus($quantityAccepted, $quantityRejected),
                'remarks' => $data['remarks'] ?? $line->remarks,
                'metadata' => [
                    ...($line->metadata ?? []),
                    ...($data['metadata'] ?? []),
                    'recorded_by' => $actor?->id,
                ],
            ]);
            $line->save();

            $this->refreshReceiptInspectionStatus($receipt);

            $this->auditLogService->log(
                $before === [] ? 'procurement.receipt_item_recorded' : 'procurement.receipt_item_updated',
                $line,
                $before,
                $line->toArray(),
                ['mda_id' => $line->mda_id, 'procurement_receipt_id' => $receipt->id],
            );

            return $line->fresh(['receipt', 'purchaseOrderItem']);
        });
    }

    public function postReceipt(ProcurementReceipt $receipt, ?User $actor = null): ProcurementReceipt
    {
        return DB::transaction(function () use ($receipt, $actor): ProcurementReceipt {
            // Serialize posting against amendments, cancellation and other receipts.
            $order = PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail();
            $receipt = ProcurementReceipt::query()
                ->with(['items.purchaseOrderItem', 'items.budgetAllocation', 'items.budgetCommitment', 'purchaseOrder.items'])
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->status === 'posted') {
                return $receipt->fresh(['items', 'purchaseOrder']);
            }

            $this->assertDraft($receipt);
            $this->assertOpenOrder($order);

            if ($receipt->items->isEmpty()) {
                throw ValidationException::withMessages(['receipt' => 'A receipt must have at least one line item before posting.']);
            }

            $before = $receipt->toArray();

            foreach ($receipt->items as $line) {
                if ((float) $line->accepted_amount <= 0) {
                    continue;
                }

                if ($line->budgetCommitment) {
                    $this->budgetLedgerService->liquidateCommitment(
                        $line->budgetCommitment,
                        (float) $line->accepted_amount,
                        $actor,
                        'Procurement receipt '.$receipt->receipt_no.' accepted for '.$line->item_description,
                    );
                } elseif ($line->budgetAllocation) {
                    $this->budgetLedgerService->recordExpenditure(
                        $line->budgetAllocation,
                        (float) $line->accepted_amount,
                        $actor,
                        'Procurement receipt '.$receipt->receipt_no.' accepted for '.$line->item_description,
                    );
                }
            }

            $receipt->forceFill([
                'status' => 'posted',
                'inspection_status' => $this->receiptInspectionStatus($receipt),
                'posted_by' => $actor?->id ?? $receipt->posted_by,
                'posted_at' => now(),
            ])->save();

            $this->refreshPurchaseOrderStatuses($receipt->purchaseOrder);

            $this->auditLogService->log('procurement.receipt_posted', $receipt, $before, $receipt->fresh('items')->toArray(), [
                'mda_id' => $receipt->mda_id,
                'purchase_order_id' => $receipt->purchase_order_id,
            ]);

            return $receipt->fresh(['items', 'purchaseOrder.items']);
        });
    }

    protected function assertDraft(ProcurementReceipt $receipt): void
    {
        if ($receipt->status !== 'draft') {
            throw ValidationException::withMessages(['receipt' => 'Only draft receipts can be changed.']);
        }
    }

    private function lockOpenOrder(ProcurementReceipt $receipt): void
    {
        $this->assertOpenOrder(PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail());
    }

    private function assertOpenOrder(PurchaseOrder $order): void
    {
        if (! in_array($order->status, ['issued', 'partially_received', 'received'], true)) {
            throw ValidationException::withMessages(['purchase_order' => 'This order is cancelled or closed.']);
        }
    }

    protected function assertReceiptItemBelongsToPurchaseOrder(ProcurementReceipt $receipt, PurchaseOrderItem $purchaseOrderItem): void
    {
        if ((int) $receipt->purchase_order_id !== (int) $purchaseOrderItem->purchase_order_id || (int) $receipt->mda_id !== (int) $purchaseOrderItem->mda_id) {
            throw ValidationException::withMessages(['purchase_order_item' => 'This item does not belong to the receipt purchase order.']);
        }
    }

    protected function assertWithinRemainingOrder(PurchaseOrderItem $purchaseOrderItem, float $quantityAccepted, float $acceptedAmount, ?ProcurementReceiptItem $currentLine = null): void
    {
        $received = ProcurementReceiptItem::query()
            ->where('purchase_order_item_id', $purchaseOrderItem->id)
            ->whereHas('receipt', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->when($currentLine?->exists, fn ($query) => $query->whereKeyNot($currentLine->id))
            ->selectRaw('COALESCE(SUM(quantity_accepted), 0) as quantity_accepted, COALESCE(SUM(accepted_amount), 0) as accepted_amount')
            ->first();

        $remainingQuantity = round((float) $purchaseOrderItem->quantity - (float) $received->quantity_accepted, 4);
        $remainingAmount = round((float) $purchaseOrderItem->total_amount - (float) $received->accepted_amount, 2);

        if ($quantityAccepted > $remainingQuantity) {
            throw ValidationException::withMessages(['quantity_accepted' => 'Accepted quantity exceeds the remaining purchase order quantity.']);
        }

        if ($acceptedAmount > $remainingAmount) {
            throw ValidationException::withMessages(['accepted_amount' => 'Accepted amount exceeds the remaining purchase order amount.']);
        }
    }

    protected function refreshReceiptInspectionStatus(ProcurementReceipt $receipt): void
    {
        $status = $this->receiptInspectionStatus($receipt->fresh('items'));
        $receipt->forceFill(['inspection_status' => $status])->save();
    }

    protected function receiptInspectionStatus(ProcurementReceipt $receipt): string
    {
        $items = $receipt->relationLoaded('items') ? $receipt->items : $receipt->items()->get();

        if ($items->isEmpty()) {
            return 'pending';
        }

        if ($items->every(fn (ProcurementReceiptItem $line): bool => (float) $line->quantity_accepted <= 0 && (float) $line->quantity_rejected > 0)) {
            return 'rejected';
        }

        if ($items->contains(fn (ProcurementReceiptItem $line): bool => (float) $line->quantity_rejected > 0 || (float) $line->quantity_accepted < (float) $line->quantity_received)) {
            return 'partial';
        }

        return 'accepted';
    }

    protected function refreshPurchaseOrderStatuses(PurchaseOrder $purchaseOrder): void
    {
        $purchaseOrder = $purchaseOrder->fresh('items');
        $hasAcceptedReceipts = false;
        $allItemsFullyReceived = true;

        foreach ($purchaseOrder->items as $item) {
            $acceptedQuantity = (float) ProcurementReceiptItem::query()
                ->where('purchase_order_item_id', $item->id)
                ->whereHas('receipt', fn ($query) => $query->where('status', 'posted'))
                ->sum('quantity_accepted');
            $acceptedAmount = (float) ProcurementReceiptItem::query()
                ->where('purchase_order_item_id', $item->id)
                ->whereHas('receipt', fn ($query) => $query->where('status', 'posted'))
                ->sum('accepted_amount');

            $lineReceived = round($acceptedQuantity, 4) >= round((float) $item->quantity, 4)
                || round($acceptedAmount, 2) >= round((float) $item->total_amount, 2);

            if ($acceptedQuantity > 0 || $acceptedAmount > 0) {
                $hasAcceptedReceipts = true;
            }

            if (! $lineReceived) {
                $allItemsFullyReceived = false;
            }

            $item->forceFill([
                'status' => $lineReceived ? 'received' : (($acceptedQuantity > 0 || $acceptedAmount > 0) ? 'partially_received' : 'ordered'),
            ])->save();
        }

        $purchaseOrder->forceFill([
            'status' => $allItemsFullyReceived ? 'received' : ($hasAcceptedReceipts ? 'partially_received' : $purchaseOrder->status),
        ])->save();
    }

    protected function receiptLineStatus(float $quantityAccepted, float $quantityRejected): string
    {
        if ($quantityAccepted <= 0 && $quantityRejected > 0) {
            return 'rejected';
        }

        return $quantityRejected > 0 ? 'partially_accepted' : 'accepted';
    }

    protected function unitPriceFromTotal(PurchaseOrderItem $purchaseOrderItem): float
    {
        $quantity = (float) $purchaseOrderItem->quantity;

        return $quantity > 0 ? round((float) $purchaseOrderItem->total_amount / $quantity, 2) : (float) $purchaseOrderItem->total_amount;
    }

    protected function nextReceiptNumber(int $mdaId): string
    {
        $next = ProcurementReceipt::query()->where('mda_id', $mdaId)->count() + 1;

        return 'GRN-'.now()->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
