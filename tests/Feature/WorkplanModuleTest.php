<?php

namespace Tests\Feature;

use App\Domain\Module\Models\MdaModule;
use App\Domain\Module\Models\Module;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Domain\Workplan\Models\Workplan;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkplanModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(RolesAndPermissionsSeeder::class); }

    public function test_mda_admin_can_create_a_draft_workplan_and_other_mda_cannot_view_it(): void
    {
        [$mdaA, $userA] = $this->mdaAdmin('HMB'); [, $userB] = $this->mdaAdmin('MOH');
        $id = $this->actingAs($userA)->postJson('/api/workplans', ['mda_id'=>$mdaA->id,'year'=>2027,'title'=>'2027 Workplan'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('workplans', ['id'=>$id,'mda_id'=>$mdaA->id,'revision_no'=>1,'status'=>'draft']);
        $this->assertDatabaseHas('audit_logs', ['event_code'=>'workplan.created','auditable_id'=>$id]);
        $this->actingAs($userB)->getJson("/api/workplans/{$id}")->assertNotFound();
    }

    public function test_activity_staff_and_targets_are_kept_within_the_workplan_mda(): void
    {
        [$mda, $user] = $this->mdaAdmin('HMB'); $department = Department::factory()->create(['mda_id'=>$mda->id]);
        $staff = Staff::query()->create(['mda_id'=>$mda->id, 'staff_number'=>'WP-001', 'surname'=>'Officer', 'first_name'=>'Responsible', 'full_name'=>'Responsible Officer', 'status'=>'active']); $staff->employments()->create(['mda_id'=>$mda->id,'department_id'=>$department->id,'is_current'=>true,'employment_status'=>'active']);
        $workplan = Workplan::query()->create(['mda_id'=>$mda->id,'year'=>2027,'revision_no'=>1,'title'=>'Plan','status'=>'draft','prepared_by'=>$user->id]);
        $objectiveId = $this->actingAs($user)->postJson("/api/workplans/{$workplan->id}/objectives", ['code'=>'OBJ-1','title'=>'Objective','department_id'=>$department->id])->assertCreated()->json('data.id');
        $activityId = $this->actingAs($user)->postJson("/api/workplan-objectives/{$objectiveId}/activities", ['activity_code'=>'ACT-1','title'=>'Activity','department_id'=>$department->id,'responsible_staff_id'=>$staff->id,'start_date'=>'2027-01-01','end_date'=>'2027-12-31'])->assertCreated()->json('data.id');
        $indicatorId = $this->actingAs($user)->postJson("/api/workplan-activities/{$activityId}/indicators", ['code'=>'KPI-1','indicator'=>'Completed items','target_mode'=>'absolute','direction'=>'increase','weight'=>1])->assertCreated()->json('data.id');
        $this->actingAs($user)->putJson("/api/workplan-indicators/{$indicatorId}/targets", ['targets'=>[['period'=>'q1','target_value'=>20],['period'=>'q2','target_value'=>50],['period'=>'q3','target_value'=>75],['period'=>'q4','target_value'=>100],['period'=>'annual','target_value'=>100]]])->assertOk();
        $this->assertDatabaseCount('workplan_indicator_targets', 5);
    }

    public function test_module_must_be_enabled_for_the_mda(): void
    {
        [$mda, $user] = $this->mdaAdmin('HMB');
        MdaModule::query()->updateOrCreate(['mda_id'=>$mda->id,'module_id'=>Module::query()->where('code','workplan_performance')->value('id')], ['enabled'=>false]);
        $this->actingAs($user)->getJson('/api/workplans')->assertForbidden();
    }

    public function test_permissions_and_global_access_are_enforced(): void
    {
        [$mdaA, $admin] = $this->mdaAdmin('HMB'); [$mdaB] = $this->mdaAdmin('MOH');
        $plan = Workplan::query()->create(['mda_id'=>$mdaB->id,'year'=>2027,'revision_no'=>1,'title'=>'Other','status'=>'draft']);
        $viewer = User::factory()->mdaUser($mdaA)->create(); $viewer->assignRole('HR Officer'); UserAccessScope::query()->create(['user_id'=>$viewer->id,'scope_type'=>'mda','mda_id'=>$mdaA->id]);
        $this->actingAs($viewer)->postJson('/api/workplans',['mda_id'=>$mdaA->id,'year'=>2027,'title'=>'No create'])->assertForbidden();
        $noAccess = User::factory()->mdaUser($mdaA)->create(); UserAccessScope::query()->create(['user_id'=>$noAccess->id,'scope_type'=>'mda','mda_id'=>$mdaA->id]);
        $this->actingAs($noAccess)->getJson('/api/workplans')->assertForbidden();
        $global = User::factory()->create(); $global->assignRole('MIS Admin'); UserAccessScope::query()->create(['user_id'=>$global->id,'scope_type'=>'state','state_code'=>'NG-NI']);
        $this->actingAs($global)->getJson("/api/workplans/{$plan->id}")->assertOk()->assertJsonPath('data.id',$plan->id);
        $this->assertNotNull($admin);
    }

    public function test_draft_metadata_duplicate_revision_and_non_draft_guard(): void
    {
        [$mda, $user] = $this->mdaAdmin('HMB');
        $id = $this->actingAs($user)->postJson('/api/workplans',['mda_id'=>$mda->id,'year'=>2027,'title'=>'Plan'])->assertCreated()->json('data.id');
        $this->actingAs($user)->putJson("/api/workplans/{$id}",['title'=>'Updated'])->assertOk()->assertJsonPath('data.title','Updated');
        $this->actingAs($user)->postJson('/api/workplans',['mda_id'=>$mda->id,'year'=>2027,'title'=>'Duplicate'])->assertUnprocessable();
        Workplan::query()->findOrFail($id)->update(['status'=>'active']);
        $this->actingAs($user)->putJson("/api/workplans/{$id}",['title'=>'Blocked'])->assertForbidden();
    }

    public function test_objective_activity_and_indicator_rules_are_enforced_and_audited(): void
    {
        [$mda,$user] = $this->mdaAdmin('HMB'); $other = Mda::factory()->create(['code'=>'EDU']); $department = Department::factory()->create(['mda_id'=>$mda->id]); $otherDepartment = Department::factory()->create(['mda_id'=>$other->id]);
        $plan = Workplan::query()->create(['mda_id'=>$mda->id,'year'=>2027,'revision_no'=>1,'title'=>'Plan','status'=>'draft']);
        $this->actingAs($user)->postJson("/api/workplans/{$plan->id}/objectives",['code'=>'BAD','title'=>'Bad','department_id'=>$otherDepartment->id])->assertUnprocessable();
        $objective = $this->actingAs($user)->postJson("/api/workplans/{$plan->id}/objectives",['code'=>'OBJ','title'=>'Objective'])->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/api/workplans/{$plan->id}/objectives",['code'=>'OBJ','title'=>'Duplicate'])->assertUnprocessable();
        $this->actingAs($user)->putJson("/api/workplan-objectives/{$objective}",['title'=>'Changed'])->assertOk();
        $bad = ['activity_code'=>'BAD','title'=>'Bad','department_id'=>$department->id,'start_date'=>'2027-12-31','end_date'=>'2027-01-01','planned_cost'=>-1];
        $this->actingAs($user)->postJson("/api/workplan-objectives/{$objective}/activities",$bad)->assertUnprocessable();
        $activity = $this->actingAs($user)->postJson("/api/workplan-objectives/{$objective}/activities",['activity_code'=>'ACT','title'=>'Activity','department_id'=>$department->id,'start_date'=>'2027-01-01','end_date'=>'2027-12-31','planned_cost'=>0])->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/api/workplan-objectives/{$objective}/activities",['activity_code'=>'ACT','title'=>'Duplicate','start_date'=>'2027-01-01','end_date'=>'2027-12-31'])->assertUnprocessable();
        $indicator = $this->actingAs($user)->postJson("/api/workplan-activities/{$activity}/indicators",['code'=>'KPI','indicator'=>'KPI','target_mode'=>'milestone','direction'=>'increase','weight'=>1])->assertUnprocessable();
        $indicator = $this->actingAs($user)->postJson("/api/workplan-activities/{$activity}/indicators",['code'=>'KPI','indicator'=>'KPI','target_mode'=>'absolute','direction'=>'increase','weight'=>1])->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/api/workplan-activities/{$activity}/indicators",['code'=>'KPI','indicator'=>'Duplicate','target_mode'=>'absolute','direction'=>'increase','weight'=>1])->assertUnprocessable();
        $this->actingAs($user)->putJson("/api/workplan-indicators/{$indicator}",['weight'=>0])->assertUnprocessable();
        foreach (['workplan.objective.created','workplan.objective.updated','workplan.activity.created','workplan.indicator.created'] as $event) $this->assertDatabaseHas('audit_logs',['event_code'=>$event]);
    }

    public function test_staff_support_target_sync_and_detail_resource_are_complete(): void
    {
        [$mda,$user] = $this->mdaAdmin('HMB'); $department=Department::factory()->create(['mda_id'=>$mda->id]); $lead=$this->staff($mda,$department,'LEAD'); $supportA=$this->staff($mda,$department,'SUP-A'); $supportB=$this->staff($mda,$department,'SUP-B');
        $plan=Workplan::query()->create(['mda_id'=>$mda->id,'year'=>2027,'revision_no'=>1,'title'=>'Plan','status'=>'draft']); $objective=$plan->objectives()->create(['mda_id'=>$mda->id,'code'=>'OBJ','title'=>'Objective']);
        $activity=$this->actingAs($user)->postJson("/api/workplan-objectives/{$objective->id}/activities",['activity_code'=>'ACT','title'=>'Activity','department_id'=>$department->id,'responsible_staff_id'=>$lead->id,'start_date'=>'2027-01-01','end_date'=>'2027-12-31'])->assertCreated()->json('data.id');
        $this->actingAs($user)->putJson("/api/workplan-activities/{$activity}/supporting-staff",['staff_ids'=>[$supportA->id,$supportB->id]])->assertOk();
        $this->actingAs($user)->putJson("/api/workplan-activities/{$activity}/supporting-staff",['staff_ids'=>[$lead->id]])->assertUnprocessable();
        $this->actingAs($user)->putJson("/api/workplan-activities/{$activity}/supporting-staff",['staff_ids'=>[$supportA->id]])->assertOk(); $this->assertDatabaseCount('workplan_activity_assignments',1);
        $indicator=$this->actingAs($user)->postJson("/api/workplan-activities/{$activity}/indicators",['code'=>'KPI','indicator'=>'Items','target_mode'=>'absolute','direction'=>'increase','weight'=>1])->assertCreated()->json('data.id');
        $this->actingAs($user)->putJson("/api/workplan-indicators/{$indicator}/targets",['targets'=>[['period'=>'q1','target_value'=>25],['period'=>'q2','target_value'=>50],['period'=>'q3','target_value'=>75],['period'=>'q4','target_value'=>100],['period'=>'annual','target_value'=>100]]])->assertOk();
        $this->actingAs($user)->putJson("/api/workplan-indicators/{$indicator}/targets",['targets'=>[['period'=>'q1','target_value'=>50],['period'=>'q2','target_value'=>25]]])->assertUnprocessable();
        $this->actingAs($user)->getJson("/api/workplans/{$plan->id}")->assertOk()->assertJsonPath('data.objectives.0.activities.0.responsible_staff.id',$lead->id)->assertJsonPath('data.objectives.0.activities.0.supporting_staff.0.id',$supportA->id)->assertJsonCount(5,'data.objectives.0.activities.0.indicators.0.targets');
        foreach (['workplan.assignment.synced','workplan.indicator_targets.synced'] as $event) $this->assertDatabaseHas('audit_logs',['event_code'=>$event]);
    }

    public function test_child_resources_are_isolated_between_mdas(): void
    {
        [$mdaA,$userA]=$this->mdaAdmin('HMB'); [$mdaB,$userB]=$this->mdaAdmin('MOH'); $plan=Workplan::query()->create(['mda_id'=>$mdaB->id,'year'=>2027,'revision_no'=>1,'title'=>'Plan','status'=>'draft']); $objective=$plan->objectives()->create(['mda_id'=>$mdaB->id,'code'=>'OBJ','title'=>'Objective']); $activity=$objective->activities()->create(['mda_id'=>$mdaB->id,'activity_code'=>'ACT','title'=>'Activity','start_date'=>'2027-01-01','end_date'=>'2027-12-31']); $indicator=$activity->indicators()->create(['mda_id'=>$mdaB->id,'code'=>'KPI','indicator'=>'KPI','target_mode'=>'absolute','direction'=>'increase','weight'=>1]);
        $this->actingAs($userA)->putJson("/api/workplan-objectives/{$objective->id}",['title'=>'Attack'])->assertNotFound();
        $this->actingAs($userA)->putJson("/api/workplan-activities/{$activity->id}/supporting-staff",['staff_ids'=>[]])->assertNotFound();
        $this->actingAs($userA)->putJson("/api/workplan-indicators/{$indicator->id}/targets",['targets'=>[]])->assertNotFound(); $this->assertNotNull($userB);
    }

    /** @return array{0:Mda,1:User} */
    protected function mdaAdmin(string $code): array
    {
        $mda = Mda::factory()->create(['code'=>$code]); $user = User::factory()->mdaUser($mda)->create(); $user->assignRole('MDA Admin');
        UserAccessScope::query()->create(['user_id'=>$user->id,'scope_type'=>'mda','mda_id'=>$mda->id]);
        return [$mda,$user];
    }

    protected function staff(Mda $mda, Department $department, string $number): Staff
    {
        $staff=Staff::query()->create(['mda_id'=>$mda->id,'staff_number'=>$number,'surname'=>'Staff','first_name'=>$number,'full_name'=>"Staff {$number}",'status'=>'active']); $staff->employments()->create(['mda_id'=>$mda->id,'department_id'=>$department->id,'is_current'=>true,'employment_status'=>'active']); return $staff;
    }
}
