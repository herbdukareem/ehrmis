<?php

namespace Tests\Unit;

use App\Domain\Approval\Services\ApprovalWorkflowService;
use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_can_be_submitted_and_approved(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $workflowService = app(ApprovalWorkflowService::class);
        $batch = LegacyStaffImportBatch::query()->create([
            'source_database' => 'ministry_of_health',
            'source_table' => 'staff_list',
            'status' => 'completed',
        ]);

        $submitter = User::factory()->create();
        $approver = User::factory()->create();
        $approver->assignRole('Approval Officer');

        $workflow = $workflowService->submit(
            $batch,
            'legacy_staff_import_publication',
            $submitter,
            [
                ['reviewer_role' => 'Approval Officer'],
            ],
        );

        $this->assertSame('submitted', $workflow->status);
        $this->assertCount(1, $workflow->steps);

        $approvedWorkflow = $workflowService->approveStep($workflow, $approver, 'Approved.');

        $this->assertSame('approved', $approvedWorkflow->status);
        $this->assertNotNull($approvedWorkflow->approved_at);
        $this->assertSame('approved', $approvedWorkflow->steps->first()->status);
    }

    public function test_workflow_cannot_be_approved_by_wrong_reviewer(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $workflowService = app(ApprovalWorkflowService::class);
        $batch = LegacyStaffImportBatch::query()->create([
            'source_database' => 'ministry_of_health',
            'source_table' => 'staff_list',
            'status' => 'completed',
        ]);

        $submitter = User::factory()->create();
        $wrongUser = User::factory()->create();

        $workflow = $workflowService->submit(
            $batch,
            'legacy_staff_import_publication',
            $submitter,
            [
                ['reviewer_role' => 'Approval Officer'],
            ],
        );

        $this->expectException(AuthorizationException::class);

        $workflowService->approveStep($workflow, $wrongUser);
    }
}
