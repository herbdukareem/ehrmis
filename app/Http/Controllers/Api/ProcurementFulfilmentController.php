<?php

namespace App\Http\Controllers\Api;

use App\Domain\Procurement\Models\ProcurementAward;
use App\Domain\Procurement\Models\ProcurementBid;
use App\Domain\Procurement\Models\ProcurementEvaluation;
use App\Domain\Procurement\Models\ProcurementReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Services\ProcurementAwardService;
use App\Domain\Procurement\Services\ProcurementReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProcurementFulfilmentController extends BudgetProcurementController
{
    public function evaluate(Request $request, ProcurementBid $bid, ProcurementAwardService $service): JsonResponse
    {
        $this->assertMda($request, $bid->mda_id);
        $this->assertCycleWritable($bid->solicitation->requisition?->budgetCycle);
        $this->assertEditable($bid->solicitation, ['published', 'closed']);
        $data = $request->validate([
            'technical_score' => ['sometimes', 'numeric', 'between:0,100'],
            'financial_score' => ['sometimes', 'numeric', 'between:0,100'],
            'total_score' => ['sometimes', 'numeric', 'between:0,200'],
            'rank' => ['sometimes', 'integer', 'min:1'],
            'recommendation_notes' => ['nullable', 'string', 'max:10000'],
            'criteria_scores' => ['nullable', 'array', 'max:100'],
            'criteria_scores.*' => ['numeric', 'between:0,100'],
        ]);

        return $this->result($service->evaluateBid($bid, $data, $request->user()));
    }

    public function recommend(Request $request, ProcurementEvaluation $evaluation, ProcurementAwardService $service): JsonResponse
    {
        $this->assertMda($request, $evaluation->mda_id);
        $this->assertCycleWritable($evaluation->solicitation->requisition?->budgetCycle);
        $data = $request->validate(['summary' => ['nullable', 'string', 'max:10000']]);

        return $this->result($service->recommendAward($evaluation, $request->user(), $data['summary'] ?? null));
    }

    public function awards(Request $request): JsonResponse
    {
        return $this->listing($request, ProcurementAward::query()->with('vendor'));
    }

    public function showAward(Request $request, ProcurementAward $award): JsonResponse
    {
        $this->assertMda($request, $award->mda_id);

        return $this->procurementDetail($award->load(['vendor', 'evaluation', 'bid', 'solicitation', 'purchaseOrder']));
    }

    public function approveAward(Request $request, ProcurementAward $award, ProcurementAwardService $service): JsonResponse
    {
        $this->assertMda($request, $award->mda_id);
        $this->assertCycleWritable($award->solicitation->requisition?->budgetCycle);

        return $this->result($service->approveAward($award, $request->user()));
    }

    public function generatePurchaseOrder(Request $request, ProcurementAward $award, ProcurementAwardService $service): JsonResponse
    {
        $this->assertMda($request, $award->mda_id);
        $this->assertCycleWritable($award->solicitation->requisition?->budgetCycle);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'order_date' => ['sometimes', 'date_format:Y-m-d'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'terms' => ['nullable', 'string', 'max:10000'],
        ]);

        $existing = $award->purchaseOrder;
        // A retry must not rebuild an issued order or reset receipt progress.
        if ($existing) {
            return $this->result($existing->load('items'));
        }

        return $this->result($service->generatePurchaseOrder($award, $request->user(), $data), 201);
    }

    public function purchaseOrders(Request $request): JsonResponse
    {
        return $this->listing($request, PurchaseOrder::query()->with('vendor'));
    }

    public function showPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->assertMda($request, $purchaseOrder->mda_id);

        return $this->procurementDetail($purchaseOrder->load(['vendor', 'items', 'receipts.items', 'award']));
    }

    public function receipts(Request $request): JsonResponse
    {
        return $this->listing($request, ProcurementReceipt::query(), ['purchase_order_id' => ['nullable', 'integer', 'min:1']]);
    }

    public function storeReceipt(Request $request, PurchaseOrder $purchaseOrder, ProcurementReceiptService $service): JsonResponse
    {
        $this->assertMda($request, $purchaseOrder->mda_id);
        $this->assertCycleWritable($purchaseOrder->requisition?->budgetCycle);
        $data = $request->validate([
            'received_date' => ['sometimes', 'date_format:Y-m-d'],
            'delivery_note_no' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        return $this->result($service->createReceipt($purchaseOrder, $data, $request->user()), 201);
    }

    public function showReceipt(Request $request, ProcurementReceipt $receipt): JsonResponse
    {
        $this->assertMda($request, $receipt->mda_id);

        return $this->procurementDetail($receipt->load(['items', 'purchaseOrder', 'vendor']));
    }

    public function storeReceiptItem(Request $request, ProcurementReceipt $receipt, ProcurementReceiptService $service): JsonResponse
    {
        $this->assertMda($request, $receipt->mda_id);
        $this->assertCycleWritable($receipt->purchaseOrder->requisition?->budgetCycle);
        $data = $request->validate([
            'purchase_order_item_id' => ['required', 'integer', $this->reference(PurchaseOrderItem::class, $receipt->mda_id, ['purchase_order_id' => $receipt->purchase_order_id])],
            'quantity_received' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:9999999999'],
            'quantity_accepted' => ['required', 'numeric', 'min:0', 'decimal:0,4', 'lte:quantity_received'],
            'quantity_rejected' => ['sometimes', 'numeric', 'min:0', 'decimal:0,4', 'lte:quantity_received'],
            'remarks' => ['nullable', 'string', 'max:10000'],
        ]);
        $received = (float) $data['quantity_received'];
        $accepted = (float) $data['quantity_accepted'];
        $rejected = (float) ($data['quantity_rejected'] ?? $received - $accepted);
        if (round($accepted + $rejected, 4) !== round($received, 4)) {
            throw ValidationException::withMessages(['quantity_rejected' => 'Accepted and rejected quantities must add up to the received quantity.']);
        }

        return $this->result($service->addItem($receipt, PurchaseOrderItem::query()->findOrFail($data['purchase_order_item_id']), $data, $request->user()), 201);
    }

    public function postReceipt(Request $request, ProcurementReceipt $receipt, ProcurementReceiptService $service): JsonResponse
    {
        $this->assertMda($request, $receipt->mda_id);
        $this->assertCycleWritable($receipt->purchaseOrder->requisition?->budgetCycle);

        return $this->result($service->postReceipt($receipt, $request->user()));
    }
}
