<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetAccount;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Procurement\Models\ProcurementPlan;
use App\Domain\Procurement\Models\ProcurementPlanLine;
use App\Domain\Procurement\Services\ProcurementPlanService;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanActivity;
use App\Domain\Workplan\Models\WorkplanObjective;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcurementPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_procurement_plan_imports_approved_workplan_activities(): void
    {
        [$mda, $department, $actor] = $this->organization();
        [$workplan, $activity] = $this->approvedWorkplanActivity($mda, $department, amount: 450000);
        $service = app(ProcurementPlanService::class);
        $plan = $service->createPlan([
            'mda_id' => $mda->id,
            'year' => 2026,
            'title' => '2026 Procurement Plan',
            'source_type' => 'workplan',
        ], $actor);

        $summary = $service->importApprovedWorkplanActivities($plan, $workplan, $actor);

        $line = ProcurementPlanLine::query()->firstOrFail();
        $this->assertSame(1, $summary['lines_synced']);
        $this->assertEquals(450000.0, $summary['estimated_amount']);
        $this->assertSame($activity->id, $line->workplan_activity_id);
        $this->assertSame(WorkplanActivity::class, $line->source_type);
        $this->assertSame($activity->id, $line->source_id);
        $this->assertSame($department->id, $line->department_id);
        $this->assertSame('request_for_quotation', $line->procurement_method);
        $this->assertSame('planned', $line->status);
        $this->assertEquals(450000.0, (float) $line->estimated_amount);
        $this->assertSame(1, $plan->fresh()->summary['line_count']);
        $this->assertDatabaseHas('audit_logs', [
            'event_code' => 'procurement_plan.workplan_imported',
            'auditable_type' => ProcurementPlan::class,
            'auditable_id' => $plan->id,
        ]);
    }

    public function test_workplan_import_is_idempotent_and_updates_existing_source_line(): void
    {
        [$mda, $department, $actor] = $this->organization();
        [$workplan, $activity] = $this->approvedWorkplanActivity($mda, $department, amount: 450000);
        $service = app(ProcurementPlanService::class);
        $plan = $service->createPlan([
            'mda_id' => $mda->id,
            'year' => 2026,
            'title' => '2026 Procurement Plan',
        ], $actor);

        $service->importApprovedWorkplanActivities($plan, $workplan, $actor);
        $activity->forceFill(['title' => 'Procure operating theatre equipment', 'planned_cost' => 6500000])->save();
        $service->importApprovedWorkplanActivities($plan, $workplan->fresh(), $actor);

        $this->assertSame(1, ProcurementPlanLine::query()->count());
        $line = ProcurementPlanLine::query()->firstOrFail();
        $this->assertSame('Procure operating theatre equipment', $line->item_description);
        $this->assertSame('open_tender', $line->procurement_method);
        $this->assertEquals(6500000.0, (float) $line->estimated_amount);
    }

    public function test_procurement_plan_imports_approved_capital_and_overhead_allocations_only(): void
    {
        [$mda, $department, $actor] = $this->organization();
        $cycle = BudgetCycle::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'name' => '2026 Annual Budget',
            'status' => 'open',
        ]);
        $capital = $this->allocation($mda, $department, $cycle, 'CAP', 'capital', 'Hospital equipment', 10000000);
        $overhead = $this->allocation($mda, $department, $cycle, 'OVH', 'overhead', 'Office supplies', 250000);
        $this->allocation($mda, $department, $cycle, 'PER', 'personnel', 'Personnel expenditure', 5000000);
        $this->allocation($mda, $department, $cycle, 'REC', 'recurrent', 'Routine operations', 800000);

        $service = app(ProcurementPlanService::class);
        $plan = $service->createPlan([
            'mda_id' => $mda->id,
            'budget_cycle_id' => $cycle->id,
            'year' => 2026,
            'title' => '2026 Procurement Plan',
            'source_type' => 'budget_allocation',
        ], $actor);

        $summary = $service->importApprovedBudgetAllocations($plan, $cycle, $actor);

        $this->assertSame(2, $summary['lines_synced']);
        $this->assertEquals(10250000.0, $summary['estimated_amount']);
        $this->assertSame(2, ProcurementPlanLine::query()->count());
        $this->assertDatabaseHas('procurement_plan_lines', [
            'budget_allocation_id' => $capital->id,
            'item_description' => 'Hospital equipment',
            'procurement_method' => 'open_tender',
        ]);
        $this->assertDatabaseHas('procurement_plan_lines', [
            'budget_allocation_id' => $overhead->id,
            'item_description' => 'Office supplies',
            'procurement_method' => 'request_for_quotation',
        ]);
    }

    public function test_manual_procurement_plan_line_can_reference_a_budget_allocation(): void
    {
        [$mda, $department, $actor] = $this->organization();
        $cycle = BudgetCycle::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'name' => '2026 Annual Budget',
            'status' => 'open',
        ]);
        $allocation = $this->allocation($mda, $department, $cycle, 'OVH', 'overhead', 'Vehicle maintenance', 900000);

        $service = app(ProcurementPlanService::class);
        $plan = $service->createPlan([
            'mda_id' => $mda->id,
            'budget_cycle_id' => $cycle->id,
            'year' => 2026,
            'title' => 'Manual Procurement Plan',
        ], $actor);

        $line = $service->addManualLine($plan, [
            'budget_allocation_id' => $allocation->id,
            'item_description' => 'Repair service vehicles',
            'quantity' => 3,
            'unit' => 'vehicles',
            'estimated_unit_cost' => 300000,
            'estimated_amount' => 900000,
            'procurement_method' => 'selective_tender',
            'priority' => 'high',
        ], $actor);

        $this->assertSame($allocation->id, $line->budget_allocation_id);
        $this->assertSame($department->id, $line->department_id);
        $this->assertSame('selective_tender', $line->procurement_method);
        $this->assertEquals(900000.0, (float) $plan->fresh()->summary['estimated_amount']);
    }

    public function test_unapproved_workplan_cannot_feed_procurement_planning(): void
    {
        [$mda, $department, $actor] = $this->organization();
        [$workplan] = $this->approvedWorkplanActivity($mda, $department, amount: 450000, status: 'draft');
        $plan = app(ProcurementPlanService::class)->createPlan([
            'mda_id' => $mda->id,
            'year' => 2026,
            'title' => '2026 Procurement Plan',
        ], $actor);

        $this->expectException(ValidationException::class);

        app(ProcurementPlanService::class)->importApprovedWorkplanActivities($plan, $workplan, $actor);
    }

    public function test_procurement_plans_are_mda_scoped(): void
    {
        [$mda, , $actor] = $this->organization();
        [$otherMda] = $this->organization('HMB');
        $service = app(ProcurementPlanService::class);

        $own = $service->createPlan(['mda_id' => $mda->id, 'year' => 2026, 'title' => 'Own Plan'], $actor);
        $service->createPlan(['mda_id' => $otherMda->id, 'year' => 2026, 'title' => 'Other Plan']);

        $this->actingAs($actor);

        $this->assertSame([$own->id], ProcurementPlan::query()->pluck('id')->all());
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

    /**
     * @return array{0:Workplan,1:WorkplanActivity}
     */
    protected function approvedWorkplanActivity(Mda $mda, Department $department, float $amount, string $status = 'approved'): array
    {
        $workplan = Workplan::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'revision_no' => 1,
            'title' => 'Annual Workplan',
            'status' => $status,
        ]);
        $objective = WorkplanObjective::query()->create([
            'mda_id' => $mda->id,
            'workplan_id' => $workplan->id,
            'department_id' => $department->id,
            'code' => 'OBJ-1',
            'title' => 'Improve service delivery',
        ]);
        $scale = SalaryScale::query()->firstOrCreate(['code' => 'GL'], [
            'name' => 'Grade Level',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);
        $activity = WorkplanActivity::query()->create([
            'mda_id' => $mda->id,
            'workplan_objective_id' => $objective->id,
            'department_id' => $department->id,
            'activity_code' => 'ACT-1',
            'title' => 'Procure medical equipment',
            'description' => 'Equipment for facilities',
            'expected_output' => 'Equipment delivered',
            'start_date' => '2026-02-01',
            'end_date' => '2026-04-30',
            'planned_cost' => $amount,
            'funding_source' => 'Budget',
            'status' => 'not_started',
            'performance_weight' => 1,
        ]);

        return [$workplan->fresh(), $activity->fresh()];
    }

    protected function allocation(
        Mda $mda,
        Department $department,
        BudgetCycle $cycle,
        string $accountCode,
        string $accountType,
        string $title,
        float $amount,
    ): BudgetAllocation {
        $account = BudgetAccount::query()->create([
            'mda_id' => $mda->id,
            'code' => $accountCode,
            'name' => $title,
            'account_type' => $accountType,
            'status' => 'active',
        ]);

        return BudgetAllocation::query()->create([
            'mda_id' => $mda->id,
            'budget_cycle_id' => $cycle->id,
            'budget_account_id' => $account->id,
            'department_id' => $department->id,
            'title' => $title,
            'approved_amount' => $amount,
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }
}
