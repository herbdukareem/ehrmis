<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Domain\Workplan\Models\Workplan;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkplanEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(RolesAndPermissionsSeeder::class); Storage::fake('local'); }

    public function test_file_and_link_evidence_are_scoped_and_files_are_removed_with_draft_evidence(): void
    {
        [$mda, $author, $report] = $this->report();
        $file = UploadedFile::fake()->create('supervision.pdf', 100, 'application/pdf');
        $fileResponse = $this->actingAs($author)->postJson("/api/workplan-progress-reports/{$report->id}/evidence", ['title'=>'Supervision note', 'evidence_type'=>'file', 'file'=>$file])->assertCreated();
        $evidenceId = $fileResponse->json('data.id');
        $path = \App\Domain\Workplan\Models\WorkplanEvidence::query()->findOrFail($evidenceId)->file_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->actingAs($author)->postJson("/api/workplan-progress-reports/{$report->id}/evidence", ['title'=>'Public report', 'evidence_type'=>'link', 'external_url'=>'https://example.test/report'])->assertCreated();
        $this->actingAs($author)->deleteJson("/api/workplan-evidence/{$evidenceId}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['event_code'=>'workplan.evidence.uploaded']);
        $this->assertDatabaseHas('audit_logs', ['event_code'=>'workplan.evidence.linked']);
        $this->assertDatabaseHas('audit_logs', ['event_code'=>'workplan.evidence.deleted']);
    }

    public function test_evidence_validation_and_verified_report_lock_are_enforced(): void
    {
        [$mda, $author, $report, $verifier] = $this->report(true);
        $this->actingAs($author)->postJson("/api/workplan-progress-reports/{$report->id}/evidence", ['title'=>'Bad file', 'evidence_type'=>'file', 'file'=>UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream')])->assertUnprocessable();
        $this->actingAs($author)->postJson("/api/workplan-progress-reports/{$report->id}/evidence", ['title'=>'Bad link', 'evidence_type'=>'link', 'external_url'=>'not-a-url'])->assertUnprocessable();
        $evidence = $this->actingAs($author)->postJson("/api/workplan-progress-reports/{$report->id}/evidence", ['title'=>'Verified evidence', 'evidence_type'=>'link', 'external_url'=>'https://example.test/evidence'])->assertCreated()->json('data.id');
        $indicatorId = $report->activity->indicators()->firstOrFail()->id;
        $this->actingAs($author)->putJson("/api/workplan-progress-reports/{$report->id}/indicators", ['indicators'=>[['workplan_indicator_id'=>$indicatorId, 'actual_value'=>25]]])->assertOk();
        $this->actingAs($author)->postJson("/api/workplan-progress-reports/{$report->id}/submit")->assertOk();
        $this->actingAs($verifier)->postJson("/api/workplan-progress-reports/{$report->id}/verify")->assertOk();
        $this->actingAs($author)->deleteJson("/api/workplan-evidence/{$evidence}")->assertUnprocessable();
        $this->actingAs($author)->postJson("/api/workplan-progress-reports/{$report->id}/evidence", ['title'=>'Late evidence', 'evidence_type'=>'link', 'external_url'=>'https://example.test/late'])->assertUnprocessable();
    }

    /** @return array{0: Mda, 1: User, 2: \App\Domain\Workplan\Models\WorkplanProgressReport, 3?: User} */
    private function report(bool $withVerifier = false): array
    {
        $mda = Mda::factory()->create();
        $author = User::factory()->mdaUser($mda)->create(); $author->givePermissionTo(['update-workplan-progress', 'view-workplans']);
        UserAccessScope::query()->create(['user_id'=>$author->id, 'scope_type'=>'mda', 'mda_id'=>$mda->id]);
        $department = Department::factory()->create(['mda_id'=>$mda->id]);
        $staff = Staff::query()->create(['mda_id'=>$mda->id, 'staff_number'=>'EV-'.$mda->id, 'surname'=>'Officer', 'first_name'=>'Evidence', 'full_name'=>'Evidence Officer', 'status'=>'active']);
        $staff->employments()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'is_current'=>true, 'employment_status'=>'active']);
        $plan = Workplan::query()->create(['mda_id'=>$mda->id, 'year'=>2027, 'revision_no'=>1, 'title'=>'Evidence plan', 'status'=>'active', 'prepared_by'=>$author->id]);
        $objective = $plan->objectives()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'code'=>'OBJ', 'title'=>'Objective', 'performance_weight'=>1]);
        $activity = $objective->activities()->create(['mda_id'=>$mda->id, 'department_id'=>$department->id, 'responsible_staff_id'=>$staff->id, 'activity_code'=>'ACT', 'title'=>'Activity', 'start_date'=>'2027-01-01', 'end_date'=>'2027-12-31', 'planned_cost'=>100, 'funding_source'=>'Budget', 'performance_weight'=>1]);
        $indicator = $activity->indicators()->create(['mda_id'=>$mda->id, 'code'=>'KPI', 'indicator'=>'Evidence', 'annual_target_value'=>100, 'target_mode'=>'absolute', 'direction'=>'increase', 'weight'=>1, 'is_required'=>true]);
        foreach (['q1'=>25, 'q2'=>50, 'q3'=>75, 'q4'=>100, 'annual'=>100] as $period=>$target) $indicator->targets()->create(['mda_id'=>$mda->id, 'period'=>$period, 'target_value'=>$target]);
        $id = $this->actingAs($author)->postJson("/api/workplan-activities/{$activity->id}/progress-reports", ['period'=>'q1'])->assertCreated()->json('data.id');
        $report = \App\Domain\Workplan\Models\WorkplanProgressReport::query()->with('activity.indicators')->findOrFail($id);
        if (!$withVerifier) return [$mda, $author, $report];
        $verifier = User::factory()->mdaUser($mda)->create(); $verifier->givePermissionTo(['verify-workplan-progress', 'view-workplans']);
        UserAccessScope::query()->create(['user_id'=>$verifier->id, 'scope_type'=>'mda', 'mda_id'=>$mda->id]);
        return [$mda, $author, $report, $verifier];
    }
}
