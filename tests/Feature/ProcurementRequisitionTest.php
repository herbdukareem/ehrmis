<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetAccount;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCommitment;
use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Budget\Models\BudgetTransaction;
use App\Domain\Budget\Services\BudgetLedgerService;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Procurement\Models\ProcurementPlan;
use App\Domain\Procurement\Models\ProcurementPlanLine;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\ProcurementRequisitionLine;
use App\Domain\Procurement\Services\ProcurementRequisitionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcurementRequisitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_requisition_creates_budget_commitments_from_plan_lines(): void
    {
        [$mda, $department, $actor] = $this->organization();
        $allocation = $this->fundedAllocation($mda, $department, 1000);
        $planLine = $this->planLine($mda, $department, $allocation, 400);
        $service = app(ProcurementRequisitionService::class);

        $requisition = $service->createRequisition([
            'mda_id' => $mda->id,
            'procurement_plan_id' => $planLine->procurement_plan_id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'department_id' => $department->id,
            'title' => 'Procure office equipment',
        ], $actor);
        $line = $service->addLine($requisition, ['procurement_plan_line_id' => $planLine->id], $actor);
        $service->submit($requisition, $actor);
        $review = $service->reviewBudgetAvailability($requisition, $actor);

        $approved = $service->approve($requisition, $actor);

        $this->assertSame('available', $review['status']);
        $this->assertSame('approved', $approved->status);
        $this->assertSame('committed', $approved->lines->first()->status);
        $this->assertSame('available', $line->fresh()->budget_check_status);
        $this->assertSame(1, BudgetCommitment::query()->count());
        $this->assertDatabaseHas('budget_transactions', [
            'budget_allocation_id' => $allocation->id,
            'transaction_type' => BudgetLedgerService::TYPE_COMMITMENT,
            'signed_amount' => 400,
        ]);

        $balance = app(BudgetLedgerService::class)->balancesForAllocation($allocation);
        $this->assertEquals(400.0, $balance['committed_amount']);
        $this->assertEquals(600.0, $balance['available_amount']);

        $service->approve($approved, $actor);
        $this->assertSame(1, BudgetCommitment::query()->count());
        $this->assertSame(1, BudgetTransaction::query()->where('transaction_type', BudgetLedgerService::TYPE_COMMITMENT)->count());
    }

    public function test_budget_review_detects_cumulative_insufficient_balance_for_same_allocation(): void
    {
        [$mda, $department, $actor] = $this->organization();
        $allocation = $this->fundedAllocation($mda, $department, 1000);
        $service = app(ProcurementRequisitionService::class);
        $requisition = $service->createRequisition([
            'mda_id' => $mda->id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'department_id' => $department->id,
            'title' => 'Two line requisition',
        ], $actor);

        $service->addLine($requisition, [
            'budget_allocation_id' => $allocation->id,
            'item_description' => 'Generator repairs',
            'estimated_amount' => 700,
        ], $actor);
        $second = $service->addLine($requisition, [
            'budget_allocation_id' => $allocation->id,
            'item_description' => 'Electrical works',
            'estimated_amount' => 400,
        ], $actor);
        $service->submit($requisition, $actor);

        $review = $service->reviewBudgetAvailability($requisition, $actor);

        $this->assertSame('insufficient', $review['status']);
        $this->assertSame(1, $review['insufficient_lines']);
        $this->assertSame('insufficient', $second->fresh()->budget_check_status);

        $this->expectException(ValidationException::class);
        $service->approve($requisition, $actor);
    }

    public function test_unmapped_requisition_line_blocks_approval(): void
    {
        [$mda, $department, $actor] = $this->organization();
        $service = app(ProcurementRequisitionService::class);
        $requisition = $service->createRequisition([
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'title' => 'Unmapped requisition',
        ], $actor);
        $service->addLine($requisition, [
            'item_description' => 'Unbudgeted vehicle',
            'estimated_amount' => 500,
        ], $actor);
        $service->submit($requisition, $actor);

        $review = $service->reviewBudgetAvailability($requisition, $actor);

        $this->assertSame('unmapped', $review['status']);
        $this->assertSame(1, $review['unmapped_lines']);

        $this->expectException(ValidationException::class);
        $service->approve($requisition, $actor);
    }

    public function test_procurement_requisitions_are_mda_scoped(): void
    {
        [$mda, $department, $actor] = $this->organization();
        [$otherMda, $otherDepartment] = $this->organization('HMB');
        $service = app(ProcurementRequisitionService::class);
        $own = $service->createRequisition([
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'title' => 'Own requisition',
        ], $actor);
        $service->createRequisition([
            'mda_id' => $otherMda->id,
            'department_id' => $otherDepartment->id,
            'title' => 'Other requisition',
        ]);

        $this->actingAs($actor);

        $this->assertSame([$own->id], ProcurementRequisition::query()->pluck('id')->all());
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

    protected function fundedAllocation(Mda $mda, Department $department, float $amount): BudgetAllocation
    {
        $cycle = BudgetCycle::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'name' => '2026 Annual Budget',
            'status' => 'open',
        ]);
        $account = BudgetAccount::query()->create([
            'mda_id' => $mda->id,
            'code' => 'OVH',
            'name' => 'Overhead',
            'account_type' => 'overhead',
            'status' => 'active',
        ]);
        $allocation = BudgetAllocation::query()->create([
            'mda_id' => $mda->id,
            'budget_cycle_id' => $cycle->id,
            'budget_account_id' => $account->id,
            'department_id' => $department->id,
            'title' => 'Overhead allocation',
            'approved_amount' => $amount,
            'status' => 'draft',
        ]);

        return app(BudgetLedgerService::class)->approveAllocation($allocation);
    }

    protected function planLine(Mda $mda, Department $department, BudgetAllocation $allocation, float $amount): ProcurementPlanLine
    {
        $plan = ProcurementPlan::query()->create([
            'mda_id' => $mda->id,
            'budget_cycle_id' => $allocation->budget_cycle_id,
            'year' => 2026,
            'title' => '2026 Procurement Plan',
            'status' => 'draft',
        ]);

        return ProcurementPlanLine::query()->create([
            'mda_id' => $mda->id,
            'procurement_plan_id' => $plan->id,
            'department_id' => $department->id,
            'budget_allocation_id' => $allocation->id,
            'item_description' => 'Office equipment',
            'quantity' => 1,
            'unit' => 'lot',
            'estimated_unit_cost' => $amount,
            'estimated_amount' => $amount,
            'procurement_method' => 'request_for_quotation',
            'status' => 'planned',
        ]);
    }
}
