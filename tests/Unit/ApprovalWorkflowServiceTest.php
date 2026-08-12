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

    public function test_workflow_step_can_be_approved_by_user_with_required_permission_without_named_role(): void
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
        $approver->givePermissionTo('approve-staff-imports');

        $workflow = $workflowService->submit(
            $batch,
            'legacy_staff_import_publication',
            $submitter,
            [
                [
                    'reviewer_role' => 'Approval Officer',
                    'metadata' => ['required_permission' => 'approve-staff-imports'],
                ],
            ],
        );

        $approvedWorkflow = $workflowService->approveStep($workflow, $approver, 'Approved with permission.');

        $this->assertSame('approved', $approvedWorkflow->status);
        $this->assertSame('approved', $approvedWorkflow->steps->first()->status);
        $this->assertSame($approver->id, $approvedWorkflow->steps->first()->acted_by);
    }

    public function test_legacy_workflow_step_without_metadata_can_still_be_approved_by_user_with_matching_permission(): void
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
        $approver->givePermissionTo('approve-staff-imports');

        $workflow = $workflowService->submit(
            $batch,
            'legacy_staff_import_publication',
            $submitter,
            [
                ['reviewer_role' => 'Approval Officer'],
            ],
        );

        $approvedWorkflow = $workflowService->approveStep($workflow, $approver, 'Approved legacy step.');

        $this->assertSame('approved', $approvedWorkflow->status);
        $this->assertSame('approved', $approvedWorkflow->steps->first()->status);
        $this->assertSame($approver->id, $approvedWorkflow->steps->first()->acted_by);
    }

    public function test_current_step_can_be_returned_for_correction(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $service = app(ApprovalWorkflowService::class); $submitter = User::factory()->create(); $reviewer = $this->approver();
        $workflow = $service->submit($this->batch(), 'generic_return_test', $submitter, [['reviewer_user_id'=>$reviewer->id], ['reviewer_user_id'=>$reviewer->id]]);
        $returned = $service->returnForCorrection($workflow, $reviewer, 'Correct the supporting evidence.'); $step = $returned->steps->first();
        $this->assertSame('returned', $returned->status); $this->assertSame('returned', $step->status);
        $this->assertSame('Correct the supporting evidence.', $step->comment); $this->assertSame($reviewer->id, $step->acted_by); $this->assertNotNull($step->acted_at);
    }

    public function test_returned_second_step_is_preserved_when_workflow_is_resubmitted(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $service = app(ApprovalWorkflowService::class); $submitter = User::factory()->create(); $reviewer = $this->approver(); $batch = $this->batch();
        $workflow = $service->submit($batch, 'generic_return_test', $submitter, [['reviewer_user_id'=>$reviewer->id], ['reviewer_user_id'=>$reviewer->id]]);
        $service->approveStep($workflow, $reviewer, 'Planning review accepted.');
        $returned = $service->returnForCorrection($workflow->fresh('steps'), $reviewer, 'Clarify the final target.');
        $resubmitted = $service->submit($batch, 'generic_return_test', $submitter, [['reviewer_user_id'=>$reviewer->id], ['reviewer_user_id'=>$reviewer->id]]);
        $history = $resubmitted->metadata['history'][0];
        $this->assertSame('returned', $history['status']); $this->assertSame('approved', $history['steps'][0]['status']); $this->assertSame('returned', $history['steps'][1]['status']);
        $this->assertSame('Clarify the final target.', $history['steps'][1]['comment']); $this->assertCount(2, $resubmitted->steps); $this->assertSame('pending', $resubmitted->steps->first()->status);
        $this->assertSame('returned', $returned->status);
    }

    public function test_rejection_remains_distinct_from_return(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $service = app(ApprovalWorkflowService::class); $submitter = User::factory()->create(); $reviewer = $this->approver();
        $workflow = $service->submit($this->batch(), 'generic_return_test', $submitter, [['reviewer_user_id'=>$reviewer->id]]);
        $rejected = $service->reject($workflow, $reviewer, 'Rejected after review.');
        $this->assertSame('rejected', $rejected->status); $this->assertSame('rejected', $rejected->steps->first()->status); $this->assertSame('Rejected after review.', $rejected->rejection_comment);
        $this->assertArrayNotHasKey('history', $rejected->metadata ?? []);
    }

    private function batch(): LegacyStaffImportBatch { return LegacyStaffImportBatch::query()->create(['source_database'=>'test','source_table'=>'test','status'=>'completed']); }
    private function approver(): User { return User::factory()->create(); }
}
