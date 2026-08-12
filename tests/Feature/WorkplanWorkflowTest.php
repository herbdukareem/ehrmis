<?php

namespace Tests\Feature;

use App\Domain\Approval\Models\ApprovalWorkflow;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Domain\Workplan\Models\Workplan;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkplanWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(RolesAndPermissionsSeeder::class); }

    public function test_intermediate_approval_return_and_resubmission_preserve_history(): void
    {
        [$mda, $author] = $this->user('MDA Admin'); [, $reviewer] = $this->user('Approval Officer', $mda);
        $plan = $this->completePlan($mda, $author);
        $this->actingAs($author)->postJson("/api/workplans/{$plan->id}/submit")->assertOk();
        $this->actingAs($reviewer)->postJson("/api/workplans/{$plan->id}/approve")->assertOk();
        $this->assertDatabaseHas('workplans', ['id'=>$plan->id, 'status'=>'under_review', 'approved_at'=>null]);
        $this->actingAs($reviewer)->postJson("/api/workplans/{$plan->id}/return", ['comment'=>'Complete the Q3 target.'])->assertOk();
        $this->actingAs($author)->postJson("/api/workplans/{$plan->id}/submit")->assertOk();

        $workflow = ApprovalWorkflow::query()->where('subject_id', $plan->id)->firstOrFail();
        $this->assertSame('submitted', $workflow->status);
        $this->assertDatabaseCount('approval_steps', 2);
        $this->actingAs($author)->getJson("/api/workplans/{$plan->id}")->assertOk()
            ->assertJsonPath('data.workflow.history.0.steps.0.status', 'approved')
            ->assertJsonPath('data.workflow.history.0.steps.1.status', 'returned')
            ->assertJsonPath('data.latest_return_reason', 'Complete the Q3 target.')
            ->assertJsonPath('data.can.submit', false);
        $this->assertDatabaseHas('audit_logs', ['event_code'=>'workplan.resubmitted', 'auditable_id'=>$plan->id]);
    }

    public function test_active_workplan_amendment_deep_clones_children_and_leaves_source_active(): void
    {
        [$mda, $author] = $this->user('MDA Admin'); [, $approver] = $this->user('Approval Officer', $mda);
        $source = $this->completePlan($mda, $author, 'active');
        $oldObjective = $source->objectives()->firstOrFail(); $oldActivity = $oldObjective->activities()->firstOrFail(); $oldIndicator = $oldActivity->indicators()->firstOrFail();
        $response = $this->actingAs($approver)->postJson("/api/workplans/{$source->id}/amendments", ['amendment_reason'=>'Updated approved allocation.'])->assertCreated();
        $newId = $response->json('data.id'); $new = Workplan::query()->findOrFail($newId);
        $this->assertSame(2, $new->revision_no); $this->assertSame('draft', $new->status->value); $this->assertSame($source->id, $new->supersedes_workplan_id);
        $this->assertSame('active', $source->fresh()->status->value);
        $newObjective = $new->objectives()->firstOrFail(); $newActivity = $newObjective->activities()->firstOrFail(); $newIndicator = $newActivity->indicators()->firstOrFail();
        $this->assertNotSame($oldObjective->id, $newObjective->id); $this->assertSame($newObjective->id, $newActivity->workplan_objective_id);
        $this->assertNotSame($oldActivity->id, $newActivity->id); $this->assertSame($newActivity->id, $newIndicator->workplan_activity_id);
        $this->assertNotSame($oldIndicator->id, $newIndicator->id); $this->assertSame(5, $newIndicator->targets()->count());
        $this->assertNull($new->submitted_at); $this->assertDatabaseHas('audit_logs', ['event_code'=>'workplan.amendment_created', 'auditable_id'=>$new->id]);
    }

    public function test_two_step_approval_prevents_skipping_and_only_final_approval_approves_plan(): void
    {
        [$mda, $author] = $this->user('MDA Admin'); $reviewer = $this->scopedUser($mda, ['review-workplans']); $final = $this->scopedUser($mda, ['approve-workplans']);
        $plan = $this->completePlan($mda, $author); $this->actingAs($author)->postJson("/api/workplans/{$plan->id}/submit")->assertOk();
        $this->actingAs($final)->postJson("/api/workplans/{$plan->id}/approve")->assertForbidden();
        $this->actingAs($reviewer)->postJson("/api/workplans/{$plan->id}/approve")->assertOk();
        $this->assertDatabaseHas('workplans', ['id'=>$plan->id, 'status'=>'under_review', 'approved_by'=>null, 'approved_at'=>null]);
        $this->assertDatabaseHas('audit_logs', ['event_code'=>'workplan.reviewed', 'auditable_id'=>$plan->id]);
        $this->actingAs($final)->postJson("/api/workplans/{$plan->id}/approve")->assertOk();
        $this->assertDatabaseHas('workplans', ['id'=>$plan->id, 'status'=>'approved', 'approved_by'=>$final->id]);
        $this->assertDatabaseHas('audit_logs', ['event_code'=>'workplan.approved', 'auditable_id'=>$plan->id]);
    }

    public function test_approved_amendment_atomically_supersedes_its_active_source(): void
    {
        [$mda, $author] = $this->user('MDA Admin'); [, $approver] = $this->user('Approval Officer', $mda);
        $source = $this->completePlan($mda, $author, 'active');
        $replacement = Workplan::query()->create(['mda_id'=>$mda->id,'year'=>2027,'revision_no'=>2,'title'=>'Revision two','status'=>'approved','supersedes_workplan_id'=>$source->id]);
        ApprovalWorkflow::query()->create(['workflow_type'=>'workplan_approval','subject_type'=>Workplan::class,'subject_id'=>$replacement->id,'status'=>'approved']);
        $this->actingAs($approver)->postJson("/api/workplans/{$replacement->id}/activate")->assertOk();
        $this->assertDatabaseHas('workplans', ['id'=>$source->id,'status'=>'superseded','superseded_by_workplan_id'=>$replacement->id]);
        $this->assertDatabaseHas('workplans', ['id'=>$replacement->id,'status'=>'active','activated_by'=>$approver->id]);
        $this->assertSame(1, Workplan::query()->where('mda_id',$mda->id)->where('year',2027)->where('status','active')->count());
        $this->assertDatabaseHas('audit_logs', ['event_code'=>'workplan.superseded','auditable_id'=>$source->id]);
    }

    public function test_amendment_uses_highest_existing_revision_number_for_its_mda_and_year(): void
    {
        [$mda, $author] = $this->user('MDA Admin'); [, $approver] = $this->user('Approval Officer', $mda);
        $source = $this->completePlan($mda, $author, 'active');
        Workplan::query()->create(['mda_id'=>$mda->id,'year'=>2027,'revision_no'=>2,'title'=>'Rejected revision','status'=>'rejected']);
        Workplan::query()->create(['mda_id'=>$mda->id,'year'=>2027,'revision_no'=>3,'title'=>'Draft revision','status'=>'draft']);
        $id = $this->actingAs($approver)->postJson("/api/workplans/{$source->id}/amendments", ['amendment_reason'=>'Correcting scope.'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('workplans', ['id'=>$id,'revision_no'=>4,'supersedes_workplan_id'=>$source->id,'status'=>'draft']);
    }

    public function test_invalid_amendment_lineage_cannot_replace_an_unrelated_active_revision(): void
    {
        [$mda, $author] = $this->user('MDA Admin'); [, $approver] = $this->user('Approval Officer', $mda);
        $active = $this->completePlan($mda, $author, 'active');
        $unrelated = Workplan::query()->create(['mda_id'=>$mda->id,'year'=>2028,'revision_no'=>1,'title'=>'Other year','status'=>'closed']);
        $candidate = Workplan::query()->create(['mda_id'=>$mda->id,'year'=>2027,'revision_no'=>2,'title'=>'Invalid lineage','status'=>'approved','supersedes_workplan_id'=>$unrelated->id]);
        ApprovalWorkflow::query()->create(['workflow_type'=>'workplan_approval','subject_type'=>Workplan::class,'subject_id'=>$candidate->id,'status'=>'approved']);
        $this->actingAs($approver)->postJson("/api/workplans/{$candidate->id}/activate")->assertUnprocessable();
        $this->assertDatabaseHas('workplans', ['id'=>$active->id,'status'=>'active']); $this->assertDatabaseHas('workplans', ['id'=>$candidate->id,'status'=>'approved']);
    }

    /** @return array{0:Mda,1:User} */
    private function user(string $role, ?Mda $mda = null): array
    {
        $mda ??= Mda::factory()->create(); $user = User::factory()->mdaUser($mda)->create(); $user->assignRole($role);
        UserAccessScope::query()->create(['user_id'=>$user->id, 'scope_type'=>'mda', 'mda_id'=>$mda->id]); return [$mda, $user];
    }

    private function scopedUser(Mda $mda, array $permissions): User
    {
        $user = User::factory()->mdaUser($mda)->create(); $user->givePermissionTo($permissions);
        UserAccessScope::query()->create(['user_id'=>$user->id, 'scope_type'=>'mda', 'mda_id'=>$mda->id]); return $user;
    }

    private function completePlan(Mda $mda, User $author, string $status = 'draft'): Workplan
    {
        $department = Department::factory()->create(['mda_id'=>$mda->id]);
        $staff = Staff::query()->create(['mda_id'=>$mda->id,'staff_number'=>'WP-'.$mda->id,'surname'=>'Officer','first_name'=>'Plan','full_name'=>'Plan Officer','status'=>'active']);
        $staff->employments()->create(['mda_id'=>$mda->id,'department_id'=>$department->id,'is_current'=>true,'employment_status'=>'active']);
        $plan = Workplan::query()->create(['mda_id'=>$mda->id,'year'=>2027,'revision_no'=>1,'title'=>'Annual plan','status'=>$status,'prepared_by'=>$author->id]);
        $objective = $plan->objectives()->create(['mda_id'=>$mda->id,'department_id'=>$department->id,'code'=>'OBJ','title'=>'Objective','performance_weight'=>1]);
        $activity = $objective->activities()->create(['mda_id'=>$mda->id,'department_id'=>$department->id,'responsible_staff_id'=>$staff->id,'activity_code'=>'ACT','title'=>'Activity','start_date'=>'2027-01-01','end_date'=>'2027-12-31','planned_cost'=>100,'funding_source'=>'Budget','performance_weight'=>1]);
        $activity->supportAssignments()->create(['mda_id'=>$mda->id,'staff_id'=>$staff->id,'role'=>'support']);
        $indicator = $activity->indicators()->create(['mda_id'=>$mda->id,'code'=>'KPI','indicator'=>'Delivery','annual_target_value'=>100,'target_mode'=>'absolute','direction'=>'increase','weight'=>1,'is_required'=>true]);
        foreach (['q1'=>25,'q2'=>50,'q3'=>75,'q4'=>100,'annual'=>100] as $period=>$target) $indicator->targets()->create(['mda_id'=>$mda->id,'period'=>$period,'target_value'=>$target]);
        return $plan;
    }
}
