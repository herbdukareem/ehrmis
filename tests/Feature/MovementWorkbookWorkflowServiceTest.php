<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementWorkbookWorkflowService;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MovementWorkbookWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_advances_and_reopens_movement_workbook_workflow(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $approver = User::factory()->create();
        $approver->assignRole('Approval Officer');

        $workbook = MovementWorkbook::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'status' => 'draft',
            'generated_at' => now(),
        ]);

        $service = app(MovementWorkbookWorkflowService::class);

        $reviewed = $service->markReviewed($workbook, $approver);
        $this->assertSame('reviewed', $reviewed->status);
        $this->assertNotNull($reviewed->reviewed_at);
        $this->assertNotNull($reviewed->approvalWorkflow);
        $this->assertSame('submitted', $reviewed->approvalWorkflow->status);

        $approved = $service->approve($reviewed, $approver);
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

    public function test_it_rejects_invalid_workflow_transitions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $workbook = MovementWorkbook::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'status' => 'draft',
        ]);

        app(MovementWorkbookWorkflowService::class)->lock($workbook);
    }
}
