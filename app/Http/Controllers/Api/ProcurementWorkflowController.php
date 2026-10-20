<?php

namespace App\Http\Controllers\Api;

use App\Domain\Procurement\Models\ProcurementAward;
use App\Domain\Procurement\Models\ProcurementPlan;
use App\Domain\Procurement\Models\ProcurementReceipt;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Services\ProcurementWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcurementWorkflowController extends BudgetProcurementController
{
    public function requisition(Request $request, ProcurementRequisition $requisition, ProcurementWorkflowService $service): JsonResponse
    {
        $this->assertMda($request, $requisition->mda_id);
        $action = $request->route()->defaults['workflowAction'];
        if ($action === 'correct') {
            $this->assertCycleWritable($requisition->budgetCycle);
        }

        return $this->result($service->requisition($requisition, $action, $this->data($request), $request->user()));
    }

    public function plan(Request $request, ProcurementPlan $plan, ProcurementWorkflowService $service): JsonResponse
    {
        $this->assertMda($request, $plan->mda_id);
        $action = $request->route()->defaults['workflowAction'];
        if ($action === 'amend') {
            $this->assertCycleWritable($plan->budgetCycle);
        }

        return $this->result($service->plan($plan, $action, $this->data($request), $request->user()));
    }

    public function award(Request $request, ProcurementAward $award, ProcurementWorkflowService $service): JsonResponse
    {
        $this->assertMda($request, $award->mda_id);

        return $this->result($service->award($award, $this->data($request), $request->user()));
    }

    public function order(Request $request, PurchaseOrder $purchaseOrder, ProcurementWorkflowService $service): JsonResponse
    {
        $this->assertMda($request, $purchaseOrder->mda_id);
        $action = $request->route()->defaults['workflowAction'];
        if ($action === 'amend') {
            $this->assertCycleWritable($purchaseOrder->requisition?->budgetCycle);
        }

        return $this->result($service->order($purchaseOrder, $action, $this->data($request), $request->user()));
    }

    public function receipt(Request $request, ProcurementReceipt $receipt, ProcurementWorkflowService $service): JsonResponse
    {
        $this->assertMda($request, $receipt->mda_id);

        return $this->result($service->cancelReceipt($receipt, $this->data($request), $request->user()));
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'reason' => ['required', 'string', 'max:5000', 'not_regex:/^\s*$/'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'justification' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'expected_delivery_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'line_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'remove_line' => ['sometimes', 'boolean'],
            'item_description' => ['sometimes', 'required', 'string', 'max:255'],
            'specification' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'quantity' => ['sometimes', 'required', 'numeric', 'gt:0', 'decimal:0,4', 'max:9999999999'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'estimated_unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999.99'],
            'estimated_amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999.99'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999.99'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);
    }
}
