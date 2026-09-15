<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Budget\Services\BudgetGenerationService;
use App\Domain\Budget\Services\BudgetWorkbookWorkflowService;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\SalaryScale;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BudgetRegenerationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $actor;

    protected MovementWorkbook $movement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $mda = Mda::factory()->create();
        $this->actor = User::factory()->mdaUser($mda)->create();
        $this->actor->assignRole('MDA Admin');
        $this->actingAs($this->actor);
        $department = Department::factory()->create(['mda_id' => $mda->id]);
        $scale = SalaryScale::query()->firstOrCreate(['code' => 'TEST'], ['name' => 'Test', 'status' => 'active']);
        $this->movement = MovementWorkbook::query()->create(['mda_id' => $mda->id, 'year' => 2026, 'status' => 'approved']);
        $this->movement->summaries()->create([
            'department_id' => $department->id, 'salary_scale_id' => $scale->id, 'level' => 8,
            'staff_count' => 1, 'current_gross_total' => 100000, 'proposed_gross_total' => 120000, 'variance_total' => 20000,
        ]);
    }

    public function test_regenerated_approved_budget_has_a_fresh_submission_and_preserves_previous_approval_history(): void
    {
        $budget = $this->approvedBudget();
        $workflowId = $budget->approvalWorkflow->id;
        $this->movement->summaries()->update(['proposed_gross_total' => 130000, 'variance_total' => 30000]);
        $response = $this->postJson('/api/budget-workbooks', ['movement_workbook_id' => $this->movement->id])->assertCreated();
        $response->assertJsonPath('data.id', $budget->id);
        $regenerated = $budget->fresh(['approvalWorkflow.steps', 'lines']);
        $this->assertSame('draft', $regenerated->status);
        $this->assertNull($regenerated->approved_by);
        $this->assertNull($regenerated->approved_at);
        $this->assertSame('draft', $regenerated->approvalWorkflow->status);
        $this->assertNull($regenerated->approvalWorkflow->approved_at);
        $this->assertCount(0, $regenerated->approvalWorkflow->steps);
        $this->assertEquals(130000, $regenerated->summary['proposed_gross_total']);
        $history = $regenerated->approvalWorkflow->metadata['history'];
        $this->assertCount(1, $history);
        $this->assertSame('approved', $history[0]['status']);
        $this->assertSame($this->actor->id, $history[0]['steps'][0]['acted_by']);
        $this->assertSame('approved', $history[0]['steps'][0]['status']);
        $this->postJson('/api/budget-workbooks/'.$budget->id.'/submit')->assertOk();
        $this->assertSame('submitted', $budget->fresh()->status);
        $resubmitted = $budget->fresh('approvalWorkflow.steps')->approvalWorkflow;
        $this->assertSame($workflowId, $resubmitted->id);
        $this->assertSame('submitted', $resubmitted->status);
        $this->assertCount(1, $resubmitted->steps);
        $this->assertSame('pending', $resubmitted->steps[0]->status);
        $this->assertNull($resubmitted->steps[0]->acted_by);
        $this->postJson('/api/budget-workbooks/'.$budget->id.'/approve')->assertOk();
        $this->assertSame('approved', $budget->fresh()->status);
        $this->assertSame('approved', $budget->fresh('approvalWorkflow')->approvalWorkflow->status);
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'budget.approval_reset_after_regeneration', 'auditable_id' => $budget->id]);

        $foreignActor = User::factory()->mdaUser()->create();
        $foreignActor->assignRole('MDA Admin');
        $this->actingAs($foreignActor)->postJson('/api/budget-workbooks', ['movement_workbook_id' => $this->movement->id])->assertForbidden();
        $this->assertSame('approved', $budget->fresh()->status);
    }

    public function test_repair_of_an_existing_stale_draft_preserves_figures_and_supports_direct_approval(): void
    {
        $budget = $this->approvedBudget();
        $budget->forceFill(['status' => 'draft'])->save();
        $before = $budget->fresh('lines');
        $workflow = app(BudgetWorkbookWorkflowService::class);
        $repaired = $workflow->resetApprovalAfterRegeneration($budget);
        $workflow->resetApprovalAfterRegeneration($repaired);
        $after = $budget->fresh(['lines', 'approvalWorkflow']);
        $this->assertSame($before->summary, $after->summary);
        $this->assertEquals($before->generated_at, $after->generated_at);
        $this->assertSame($before->lines->toArray(), $after->lines->toArray());
        $this->assertCount(1, $after->approvalWorkflow->metadata['history']);
        $this->assertSame('draft', $after->status);
        $this->postJson('/api/budget-workbooks/'.$budget->id.'/approve')->assertOk();
        $this->assertSame('approved', $budget->fresh('approvalWorkflow')->approvalWorkflow->status);
        $this->assertSame($this->actor->id, $budget->fresh('approvalWorkflow.steps')->approvalWorkflow->steps[0]->acted_by);
    }

    public function test_repair_cannot_reset_a_budget_that_is_still_approved(): void
    {
        $budget = $this->approvedBudget();
        $this->expectException(InvalidArgumentException::class);
        app(BudgetWorkbookWorkflowService::class)->resetApprovalAfterRegeneration($budget);
    }

    protected function approvedBudget(): BudgetWorkbook
    {
        $draft = app(BudgetGenerationService::class)->generateFromMovementWorkbook($this->movement->fresh('summaries'), $this->actor->id);

        return app(BudgetWorkbookWorkflowService::class)->approve($draft, $this->actor);
    }
}
