<?php

namespace App\Http\Controllers\Api;

use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Organization\Models\Department;
use App\Domain\Procurement\Models\ProcurementPlan;
use App\Domain\Procurement\Models\ProcurementPlanLine;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Services\ProcurementPlanService;
use App\Domain\Procurement\Services\ProcurementRequisitionService;
use App\Domain\Procurement\Services\ProcurementSourcingService;
use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProcurementPlanningController extends BudgetProcurementController
{
    public function plans(Request $request): JsonResponse
    {
        return $this->listing($request, ProcurementPlan::query(), ['year' => ['nullable', 'integer', 'between:2020,2100']]);
    }

    public function storePlan(Request $request, ProcurementPlanService $service): JsonResponse
    {
        $mdaId = $this->selectedMda($request);
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'budget_cycle_id' => ['nullable', 'integer', $this->reference(BudgetCycle::class, $mdaId, ['year' => $request->integer('year')])],
        ]);
        $this->assertCycleWritable(isset($data['budget_cycle_id']) ? BudgetCycle::query()->findOrFail($data['budget_cycle_id']) : null);

        return $this->result($service->createPlan(['mda_id' => $mdaId, ...$data], $request->user()), 201);
    }

    public function showPlan(Request $request, ProcurementPlan $plan): JsonResponse
    {
        $this->assertMda($request, $plan->mda_id);

        return $this->procurementDetail($plan->load(['lines.budgetAllocation', 'budgetCycle']));
    }

    public function storePlanLine(Request $request, ProcurementPlan $plan, ProcurementPlanService $service): JsonResponse
    {
        $this->assertMda($request, $plan->mda_id);
        $this->assertEditable($plan);
        $this->assertCycleWritable($plan->budgetCycle);
        $data = $request->validate([
            ...$this->lineRules($plan->mda_id, $plan->budget_cycle_id),
            'workplan_activity_id' => ['nullable', 'integer', $this->reference(WorkplanActivity::class, $plan->mda_id)],
            'procurement_method' => ['sometimes', Rule::in(ProcurementSourcingService::SUPPORTED_METHODS)],
            'planned_start_date' => ['nullable', 'date_format:Y-m-d'],
            'planned_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:planned_start_date'],
        ]);

        return $this->result($service->addManualLine($plan, $data, $request->user()), 201);
    }

    public function importWorkplan(Request $request, ProcurementPlan $plan, ProcurementPlanService $service): JsonResponse
    {
        $this->assertMda($request, $plan->mda_id);
        $this->assertEditable($plan);
        $this->assertCycleWritable($plan->budgetCycle);
        $data = $request->validate(['workplan_id' => ['required', 'integer', $this->reference(Workplan::class, $plan->mda_id, ['year' => $plan->year])]]);

        return $this->result($service->importApprovedWorkplanActivities($plan, Workplan::query()->findOrFail($data['workplan_id']), $request->user()));
    }

    public function importAllocations(Request $request, ProcurementPlan $plan, ProcurementPlanService $service): JsonResponse
    {
        $this->assertMda($request, $plan->mda_id);
        $this->assertEditable($plan);
        $this->assertCycleWritable($plan->budgetCycle);
        $data = $request->validate(['budget_cycle_id' => ['nullable', 'integer', $this->reference(BudgetCycle::class, $plan->mda_id, ['year' => $plan->year])]]);
        $cycle = isset($data['budget_cycle_id']) ? BudgetCycle::query()->findOrFail($data['budget_cycle_id']) : $plan->budgetCycle;
        $this->assertCycleWritable($cycle);
        if ($plan->budget_cycle_id && $cycle && $cycle->id !== $plan->budget_cycle_id) {
            throw ValidationException::withMessages(['budget_cycle_id' => 'Use the procurement plan budget cycle.']);
        }

        return $this->result($service->importApprovedBudgetAllocations($plan, $cycle, $request->user()));
    }

    public function requisitions(Request $request): JsonResponse
    {
        return $this->listing($request, ProcurementRequisition::query(), ['procurement_plan_id' => ['nullable', 'integer', 'min:1']]);
    }

    public function storeRequisition(Request $request, ProcurementRequisitionService $service): JsonResponse
    {
        $mdaId = $this->selectedMda($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'justification' => ['nullable', 'string', 'max:10000'],
            'procurement_plan_id' => ['nullable', 'integer', $this->reference(ProcurementPlan::class, $mdaId)],
            'budget_cycle_id' => ['nullable', 'integer', $this->reference(BudgetCycle::class, $mdaId)],
            'department_id' => ['nullable', 'integer', $this->reference(Department::class, $mdaId)],
        ]);
        $plan = isset($data['procurement_plan_id']) ? ProcurementPlan::query()->findOrFail($data['procurement_plan_id']) : null;
        if ($plan) {
            $this->assertEditable($plan, ['draft', 'approved']);
        }
        $cycleId = $data['budget_cycle_id'] ?? $plan?->budget_cycle_id;
        $cycle = $cycleId ? BudgetCycle::query()->findOrFail($cycleId) : null;
        $this->assertCycleWritable($cycle);
        if ($plan && $cycle && ($cycle->year !== $plan->year || ($plan->budget_cycle_id && $cycle->id !== $plan->budget_cycle_id))) {
            throw ValidationException::withMessages(['budget_cycle_id' => 'Use the procurement plan budget cycle and year.']);
        }

        return $this->result($service->createRequisition(['mda_id' => $mdaId, ...$data, 'budget_cycle_id' => $cycleId], $request->user()), 201);
    }

    public function showRequisition(Request $request, ProcurementRequisition $requisition): JsonResponse
    {
        $this->assertMda($request, $requisition->mda_id);

        return $this->procurementDetail($requisition->load(['lines.budgetAllocation', 'lines.budgetCommitment']));
    }

    public function storeRequisitionLine(Request $request, ProcurementRequisition $requisition, ProcurementRequisitionService $service): JsonResponse
    {
        $this->assertMda($request, $requisition->mda_id);
        $this->assertCycleWritable($requisition->budgetCycle);
        $constraints = $requisition->procurement_plan_id ? ['procurement_plan_id' => $requisition->procurement_plan_id] : [];
        $data = $request->validate([
            ...$this->lineRules($requisition->mda_id, $requisition->budget_cycle_id),
            'procurement_plan_line_id' => ['nullable', 'integer', $this->reference(ProcurementPlanLine::class, $requisition->mda_id, $constraints)],
        ]);
        if (! empty($data['procurement_plan_line_id']) && $requisition->budget_cycle_id) {
            $planLine = ProcurementPlanLine::query()->findOrFail($data['procurement_plan_line_id']);
            if ($planLine->budgetAllocation && $planLine->budgetAllocation->budget_cycle_id !== $requisition->budget_cycle_id) {
                throw ValidationException::withMessages(['procurement_plan_line_id' => 'This plan line belongs to another budget cycle.']);
            }
        }

        return $this->result($service->addLine($requisition, $data, $request->user()), 201);
    }

    public function submitRequisition(Request $request, ProcurementRequisition $requisition, ProcurementRequisitionService $service): JsonResponse
    {
        $this->assertMda($request, $requisition->mda_id);
        $this->assertCycleWritable($requisition->budgetCycle);

        return $this->result($service->submit($requisition, $request->user()));
    }

    public function reviewBudget(Request $request, ProcurementRequisition $requisition, ProcurementRequisitionService $service): JsonResponse
    {
        $this->assertMda($request, $requisition->mda_id);
        $this->assertCycleWritable($requisition->budgetCycle);
        $this->assertEditable($requisition, ['draft', 'returned', 'submitted', 'budget_reviewed']);

        return $this->result($service->reviewBudgetAvailability($requisition, $request->user()));
    }

    public function approveRequisition(Request $request, ProcurementRequisition $requisition, ProcurementRequisitionService $service): JsonResponse
    {
        $this->assertMda($request, $requisition->mda_id);
        $this->assertCycleWritable($requisition->budgetCycle);

        return $this->result($service->approve($requisition, $request->user()));
    }

    private function lineRules(int $mdaId, ?int $cycleId): array
    {
        return [
            'item_description' => ['required', 'string', 'max:255'],
            'specification' => ['nullable', 'string', 'max:10000'],
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,4', 'max:9999999999'],
            'unit' => ['nullable', 'string', 'max:50'],
            'estimated_unit_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999999999.99'],
            'estimated_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999999.99'],
            'budget_allocation_id' => ['nullable', 'integer', $this->reference(BudgetAllocation::class, $mdaId, ['status' => 'approved', ...($cycleId ? ['budget_cycle_id' => $cycleId] : [])])],
            'department_id' => ['nullable', 'integer', $this->reference(Department::class, $mdaId)],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
