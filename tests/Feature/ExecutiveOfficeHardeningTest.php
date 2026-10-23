<?php

namespace Tests\Feature;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Services\OfficeAccess;
use App\Domain\ExecutiveOffice\Services\OfficeAdministrationService;
use App\Domain\ExecutiveOffice\Services\OfficeRecordService;
use App\Domain\ExecutiveOffice\Services\OfficeWorkflowService;
use App\Domain\Module\Models\MdaModule;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Database\Seeders\ExecutiveOfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExecutiveOffice;
use Tests\TestCase;

class ExecutiveOfficeHardeningTest extends TestCase
{
    use BuildsExecutiveOffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officeFixtures();
    }

    public function test_seeding_is_idempotent_and_never_activates_other_mdas_or_grants_record_membership(): void
    {
        $other = Mda::factory()->create();
        $this->seed(ExecutiveOfficeSeeder::class);
        $this->seed(ExecutiveOfficeSeeder::class);
        $this->assertSame(0, MdaModule::query()->where('mda_id', $other->id)->whereHas('module', fn ($q) => $q->where('code', 'executive_office'))->count());
        $this->assertSame(4, $this->office->memberships()->count());
    }

    public function test_approved_outgoing_correspondence_can_be_reopened_and_cannot_dispatch_an_old_approval(): void
    {
        $records = app(OfficeRecordService::class);
        $workflow = app(OfficeWorkflowService::class);
        $row = $records->create($this->pa, $this->office, ['type' => 'correspondence', 'subject' => 'Letter', 'priority' => 'normal', 'confidentiality' => 'standard', 'details' => ['direction' => 'outgoing', 'sender' => 'Office', 'recipient' => 'Ministry']]);
        $row = $workflow->act($this->pa, $this->office, $row, ['action' => 'submit_decision', 'version' => 1]);
        $row = $workflow->act($this->executive, $this->office, $row, ['action' => 'approve', 'version' => 2]);
        $url = $this->officeUrl('records/'.$row->id.'/actions');
        $this->actingAs($this->pa)->postJson($url, ['action' => 'reopen', 'version' => 3, 'comment' => 'Correct recipient'])->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.approved_version', null);
        $this->postJson($url, ['action' => 'dispatch', 'version' => 4, 'dispatch_method' => 'Hand delivery', 'dispatch_recipient' => 'Ministry'])->assertForbidden();
    }

    public function test_delegated_authority_expires_without_a_scheduler_or_session_refresh(): void
    {
        $delegate = User::factory()->mdaUser($this->mda)->create();
        $this->actingAs($this->executive)->postJson($this->officeUrl('delegations'), ['user_id' => $delegate->id, 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addMinute()->toIso8601String(), 'abilities' => ['read', 'schedule'], 'executive_only_access' => false, 'reason' => 'Short cover'])->assertCreated();
        $this->assertContains('schedule', app(OfficeAccess::class)->abilities($delegate, $this->office));
        $this->travel(2)->minutes();
        $this->assertSame([], app(OfficeAccess::class)->abilities($delegate, $this->office));
    }

    public function test_an_office_administrator_can_hand_over_their_own_administrative_assignment(): void
    {
        $outgoing = User::factory()->mdaUser($this->mda)->create();
        $incoming = User::factory()->mdaUser($this->mda)->create();
        app(OfficeAdministrationService::class)->assign($this->admin, $this->office, ['user_id' => $outgoing->id, 'role' => 'administrator', 'starts_at' => now()->subHour()->toIso8601String()]);
        $this->actingAs($outgoing)->postJson($this->officeUrl('handover'), ['outgoing_user_id' => $outgoing->id, 'incoming_user_id' => $incoming->id, 'reason' => 'Administrator handover'])->assertOk();
        $this->assertFalse(app(OfficeAccess::class)->configure($outgoing, $this->mda->id));
        $this->assertTrue(app(OfficeAccess::class)->configure($incoming, $this->mda->id));
    }

    public function test_expected_visitors_have_scheduled_arrivals_and_only_the_office_can_confirm_the_host(): void
    {
        $input = ['type' => 'visitor', 'subject' => 'Expected visit', 'priority' => 'normal', 'confidentiality' => 'standard',
            'details' => ['visitor_name' => 'Guest', 'host' => 'Executive', 'purpose' => 'Official visit', 'walk_in' => false]];
        $this->actingAs($this->registry)->postJson($this->officeUrl('records'), $input)->assertUnprocessable();
        $response = $this->actingAs($this->pa)->postJson($this->officeUrl('records'), $input + ['starts_at' => now()->addHour()->toIso8601String()])->assertCreated();
        $id = $response->json('data.id');
        $this->actingAs($this->registry)->postJson($this->officeUrl('records/'.$id.'/actions'), ['action' => 'confirm_host', 'version' => 1])->assertForbidden();
        $this->actingAs($this->pa)->postJson($this->officeUrl('records/'.$id.'/actions'), ['action' => 'confirm_host', 'version' => 1])->assertOk()->assertJsonStructure(['data' => ['details' => ['host_confirmed_at', 'host_confirmed_by']]]);
    }

    public function test_an_assignee_can_record_interim_progress_without_submitting_the_work_as_complete(): void
    {
        $record = app(OfficeRecordService::class)->create($this->executive, $this->office, ['type' => 'directive', 'subject' => 'Report', 'priority' => 'normal', 'confidentiality' => 'standard', 'assigned_to' => $this->officer->id, 'due_at' => now()->addDay()->toIso8601String(), 'details' => ['instruction' => 'Prepare report']]);
        $record = app(OfficeWorkflowService::class)->act($this->executive, $this->office, $record, ['action' => 'issue', 'version' => 1]);
        $this->actingAs($this->officer)->postJson($this->officeUrl('records/'.$record->id.'/actions'), ['action' => 'update_progress', 'version' => 2, 'comment' => 'Two departments have responded; waiting for the third.'])->assertOk()->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.closed_at', null);
        $this->assertSame('update_progress', $record->events()->latest('id')->first()->action);
    }

    public function test_a_foreign_record_cannot_be_retrieved_using_the_callers_own_mda_context(): void
    {
        $other = Mda::factory()->create();
        $foreignOffice = Office::query()->create(['mda_id' => $other->id, 'title' => 'General Manager', 'reference_prefix' => 'OTHER']);
        $record = $foreignOffice->records()->create(['mda_id' => $other->id, 'type' => 'correspondence', 'reference' => 'OTHER/1', 'subject' => 'Foreign record', 'status' => 'received', 'priority' => 'normal', 'confidentiality' => 'standard', 'created_by' => $this->executive->id, 'details' => []]);
        $this->actingAs($this->pa)->getJson($this->officeUrl('records/'.$record->id))->assertNotFound();
        $this->postJson($this->officeUrl('records/'.$record->id.'/notes'), ['body' => 'Attempt', 'visibility' => 'office'])->assertNotFound();
    }
}
