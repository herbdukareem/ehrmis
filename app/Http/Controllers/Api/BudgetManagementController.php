<?php

namespace App\Http\Controllers\Api;

use App\Domain\Budget\Models\BudgetAccount;
use App\Domain\Budget\Models\BudgetAdjustment;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCommitment;
use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Budget\Models\BudgetRelease;
use App\Domain\Budget\Models\BudgetTransaction;
use App\Domain\Budget\Services\BudgetCycleService;
use App\Domain\Budget\Services\BudgetLedgerService;
use App\Domain\Organization\Models\Department;
use App\Domain\Workplan\Models\WorkplanActivity;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BudgetManagementController extends BudgetProcurementController
{
    public function cycles(Request $request): JsonResponse
    {
        return $this->listing($request, BudgetCycle::query(), ['year' => ['nullable', 'integer', 'between:2020,2100']]);
    }

    public function storeCycle(Request $request, BudgetCycleService $service): JsonResponse
    {
        $mdaId = $this->selectedMda($request);
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100', $this->uniqueWithinMda('budget_cycles', 'year', $mdaId)],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->result($service->create(['mda_id' => $mdaId, ...$data], $request->user()), 201);
    }

    public function showCycle(Request $request, BudgetCycle $cycle): JsonResponse
    {
        $this->assertMda($request, $cycle->mda_id);

        return $this->result($cycle->loadCount(['allocations', 'releases', 'commitments']));
    }

    public function submitCycle(Request $request, BudgetCycle $cycle, BudgetCycleService $service): JsonResponse
    {
        $this->assertMda($request, $cycle->mda_id);

        return $this->result($service->submit($cycle, $request->user()));
    }

    public function approveCycle(Request $request, BudgetCycle $cycle, BudgetCycleService $service): JsonResponse
    {
        $this->assertMda($request, $cycle->mda_id);

        return $this->result($service->approve($cycle, $request->user()));
    }

    public function accounts(Request $request): JsonResponse
    {
        return $this->listing($request, BudgetAccount::query());
    }

    public function storeAccount(Request $request, AuditLogService $audit): JsonResponse
    {
        $mdaId = $this->selectedMda($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', $this->uniqueWithinMda('budget_accounts', 'code', $mdaId)],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', $this->reference(BudgetAccount::class, $mdaId)],
            'account_type' => ['required', Rule::in(['personnel', 'recurrent', 'overhead', 'capital', 'capital_expenditure', 'overhead_expenditure'])],
            'description' => ['nullable', 'string', 'max:10000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        $account = DB::transaction(function () use ($data, $mdaId, $audit): BudgetAccount {
            $account = BudgetAccount::query()->create(['mda_id' => $mdaId, ...$data, 'status' => 'active']);
            $audit->logCreated($account, ['source' => 'budget_account.created']);

            return $account;
        });

        return $this->result($account, 201);
    }

    public function allocations(Request $request): JsonResponse
    {
        return $this->listing($request, BudgetAllocation::query()->with(['account', 'department']), [
            'budget_cycle_id' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    public function storeAllocation(Request $request, BudgetCycle $cycle, AuditLogService $audit): JsonResponse
    {
        $this->assertMda($request, $cycle->mda_id);
        $this->assertCycleWritable($cycle);
        $data = $request->validate([
            'budget_account_id' => ['required', 'integer', $this->reference(BudgetAccount::class, $cycle->mda_id, ['status' => 'active'])],
            'department_id' => ['nullable', 'integer', $this->reference(Department::class, $cycle->mda_id)],
            'workplan_activity_id' => ['nullable', 'integer', $this->reference(WorkplanActivity::class, $cycle->mda_id)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'approved_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
        ]);
        $allocation = DB::transaction(function () use ($data, $cycle, $request, $audit): BudgetAllocation {
            $allocation = BudgetAllocation::query()->create([
                ...$data, 'mda_id' => $cycle->mda_id, 'budget_cycle_id' => $cycle->id,
                'status' => 'draft', 'allocated_by' => $request->user()->id,
            ]);
            $audit->logCreated($allocation, ['source' => 'budget_allocation.created']);

            return $allocation;
        });

        return $this->result($allocation, 201);
    }

    public function showAllocation(Request $request, BudgetAllocation $allocation, BudgetLedgerService $ledger): JsonResponse
    {
        $this->assertMda($request, $allocation->mda_id);

        return $this->result([
            ...$allocation->load(['cycle', 'account', 'department'])->toArray(),
            'balances' => $ledger->balancesForAllocation($allocation),
        ]);
    }

    public function approveAllocation(Request $request, BudgetAllocation $allocation, BudgetLedgerService $ledger): JsonResponse
    {
        $this->assertMda($request, $allocation->mda_id);
        $this->assertCycleWritable($allocation->cycle);
        $this->assertEditable($allocation, ['draft', 'approved']);

        return $this->result($ledger->approveAllocation($allocation, $request->user()));
    }

    public function releases(Request $request): JsonResponse
    {
        return $this->listing($request, BudgetRelease::query(), ['budget_allocation_id' => ['nullable', 'integer', 'min:1']]);
    }

    public function storeRelease(Request $request, BudgetAllocation $allocation, AuditLogService $audit): JsonResponse
    {
        $this->assertApprovedAllocation($request, $allocation);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999999.99'],
            'release_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $release = DB::transaction(function () use ($data, $allocation, $audit): BudgetRelease {
            $release = $allocation->releases()->create([
                ...$data, 'mda_id' => $allocation->mda_id, 'budget_cycle_id' => $allocation->budget_cycle_id, 'status' => 'draft',
            ]);
            $audit->logCreated($release, ['source' => 'budget_release.created']);

            return $release;
        });

        return $this->result($release, 201);
    }

    public function postRelease(Request $request, BudgetRelease $release, BudgetLedgerService $ledger): JsonResponse
    {
        $this->assertMda($request, $release->mda_id);
        $this->assertApprovedAllocation($request, $release->allocation);
        $this->assertEditable($release, ['draft', 'posted']);

        return $this->result($ledger->postRelease($release, $request->user()));
    }

    public function adjustments(Request $request): JsonResponse
    {
        return $this->listing($request, BudgetAdjustment::query(), ['budget_allocation_id' => ['nullable', 'integer', 'min:1']]);
    }

    public function storeAdjustment(Request $request, BudgetAllocation $allocation, AuditLogService $audit): JsonResponse
    {
        $this->assertApprovedAllocation($request, $allocation);
        $data = $request->validate([
            'adjustment_type' => ['required', Rule::in(['increase', 'decrease'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999999.99'],
            'reason' => ['required', 'string', 'max:10000'],
        ]);
        $adjustment = DB::transaction(function () use ($data, $allocation, $audit): BudgetAdjustment {
            $adjustment = $allocation->adjustments()->create([
                ...$data, 'mda_id' => $allocation->mda_id, 'budget_cycle_id' => $allocation->budget_cycle_id, 'status' => 'draft',
            ]);
            $audit->logCreated($adjustment, ['source' => 'budget_adjustment.created']);

            return $adjustment;
        });

        return $this->result($adjustment, 201);
    }

    public function approveAdjustment(Request $request, BudgetAdjustment $adjustment, BudgetLedgerService $ledger): JsonResponse
    {
        $this->assertMda($request, $adjustment->mda_id);
        $this->assertApprovedAllocation($request, $adjustment->allocation);
        $this->assertEditable($adjustment, ['draft', 'approved']);

        return $this->result($ledger->approveAdjustment($adjustment, $request->user()));
    }

    public function commitments(Request $request): JsonResponse
    {
        return $this->listing($request, BudgetCommitment::query(), ['budget_allocation_id' => ['nullable', 'integer', 'min:1']]);
    }

    public function transactions(Request $request): JsonResponse
    {
        return $this->listing($request, BudgetTransaction::query(), [
            'budget_cycle_id' => ['nullable', 'integer', 'min:1'],
            'budget_allocation_id' => ['nullable', 'integer', 'min:1'],
            'transaction_type' => ['nullable', Rule::in(['allocation', 'release', 'adjustment', 'commitment', 'commitment_release', 'commitment_liquidation', 'expenditure'])],
            // Transactions do not have a workflow status.
            'status' => ['prohibited'],
        ]);
    }

    private function assertApprovedAllocation(Request $request, BudgetAllocation $allocation): void
    {
        $this->assertMda($request, $allocation->mda_id);
        $this->assertCycleWritable($allocation->cycle);
        if ($allocation->status !== 'approved') {
            throw ValidationException::withMessages(['budget_allocation_id' => 'An approved allocation is required.']);
        }
    }
}
