<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Budget\Services\BudgetWorkbookWorkflowService;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BudgetWorkbookWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_advances_and_reopens_budget_workbook_workflow(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $movementWorkbook = MovementWorkbook::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'status' => 'approved',
        ]);

        $approver = User::factory()->create();
        $approver->assignRole('Approval Officer');

        $workbook = BudgetWorkbook::query()->create([
            'mda_id' => $mda->id,
            'movement_workbook_id' => $movementWorkbook->id,
            'year' => 2026,
            'status' => 'draft',
            'generated_at' => now(),
        ]);

        $service = app(BudgetWorkbookWorkflowService::class);

        $submitted = $service->submit($workbook, $approver);
        $this->assertSame('submitted', $submitted->status);
        $this->assertNotNull($submitted->approvalWorkflow);
        $this->assertSame('submitted', $submitted->approvalWorkflow->status);

        $approved = $service->approve($submitted, $approver);
        $this->assertSame('approved', $approved->status);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame('approved', $approved->approvalWorkflow->status);

        $locked = $service->lock($approved);
        $this->assertSame('locked', $locked->status);
        $this->assertNotNull($locked->locked_at);
        $this->assertSame('locked', $locked->approvalWorkflow->status);

        $reopened = $service->reopen($locked);
        $this->assertSame('reopened', $reopened->status);
        $this->assertNull($reopened->locked_at);
        $this->assertSame('draft', $reopened->approvalWorkflow->status);

        $this->assertSame(7, AuditLog::query()->count());
    }

    public function test_it_rejects_invalid_budget_workflow_transitions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $movementWorkbook = MovementWorkbook::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'status' => 'approved',
        ]);

        $workbook = BudgetWorkbook::query()->create([
            'mda_id' => $mda->id,
            'movement_workbook_id' => $movementWorkbook->id,
            'year' => 2026,
            'status' => 'draft',
        ]);

        app(BudgetWorkbookWorkflowService::class)->lock($workbook);
    }
}
