<?php

namespace Tests\Feature;

use App\Domain\ExecutiveOffice\Services\OfficeAccess;
use App\Domain\ExecutiveOffice\Services\OfficeRecordService;
use App\Domain\ExecutiveOffice\Services\OfficeWorkflowService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExecutiveOffice;
use Tests\TestCase;

class ExecutiveOfficeContinuityTest extends TestCase
{
    use BuildsExecutiveOffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officeFixtures();
    }

    public function test_delegation_has_limited_dated_authority_and_revocation_is_immediate(): void
    {
        $delegate = User::factory()->mdaUser($this->mda)->create();
        $grant = $this->actingAs($this->executive)->postJson($this->officeUrl('delegations'), ['user_id' => $delegate->id, 'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(), 'abilities' => ['read', 'schedule'], 'executive_only_access' => false, 'reason' => 'Temporary diary cover'])->assertCreated()->json('data');
        $this->assertContains('schedule', app(OfficeAccess::class)->abilities($delegate, $this->office));
        $this->assertNotContains('decide', app(OfficeAccess::class)->abilities($delegate, $this->office));
        $this->actingAs($delegate)->postJson($this->officeUrl('delegations'), ['user_id' => $this->pa->id])->assertForbidden();
        $this->actingAs($this->executive)->deleteJson($this->officeUrl('delegations/'.$grant['id']))->assertOk();
        $this->assertSame([], app(OfficeAccess::class)->abilities($delegate, $this->office));
    }

    public function test_handover_preserves_authorship_reassigns_open_matters_and_removes_outgoing_access(): void
    {
        $incoming = User::factory()->mdaUser($this->mda)->create();
        $record = app(OfficeRecordService::class)->create($this->pa, $this->office, ['type' => 'directive', 'subject' => 'Follow up', 'priority' => 'normal', 'confidentiality' => 'standard', 'assigned_to' => $this->pa->id, 'due_at' => now()->addDay()->toIso8601String(), 'details' => ['instruction' => 'Prepare report']]);
        $this->actingAs($this->admin)->postJson($this->officeUrl('handover'), ['outgoing_user_id' => $this->pa->id, 'incoming_user_id' => $incoming->id, 'reason' => 'Change of PA'])->assertOk()->assertJsonPath('data.transferred_records', 1);
        $this->assertSame((int) $incoming->id, (int) $record->fresh()->assigned_to);
        $this->assertSame((int) $this->pa->id, (int) $record->fresh()->created_by);
        $this->assertSame([], app(OfficeAccess::class)->abilities($this->pa, $this->office));
        $this->assertContains('lead_pa', app(OfficeAccess::class)->roles($incoming, $this->office));
    }

    public function test_archive_and_reopen_keep_history_and_require_a_reason(): void
    {
        $record = app(OfficeRecordService::class)->create($this->pa, $this->office, ['type' => 'visitor', 'subject' => 'Visit', 'priority' => 'normal', 'confidentiality' => 'standard', 'details' => ['visitor_name' => 'Guest', 'purpose' => 'Visit', 'host' => 'Executive', 'walk_in' => true]]);
        $workflow = app(OfficeWorkflowService::class);
        $record = $workflow->act($this->pa, $this->office, $record, ['action' => 'arrive', 'version' => 1]);
        $record = $workflow->act($this->pa, $this->office, $record, ['action' => 'depart', 'version' => 2, 'outcome' => 'Completed', 'received_by_executive' => true]);
        $url = $this->officeUrl('records/'.$record->id.'/actions');
        $this->actingAs($this->pa)->postJson($url, ['action' => 'archive', 'version' => 3])->assertOk();
        $this->getJson($this->officeUrl('records'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($url, ['action' => 'reopen', 'version' => 4])->assertUnprocessable();
        $this->postJson($url, ['action' => 'reopen', 'version' => 4, 'comment' => 'Correct visit outcome'])->assertOk()->assertJsonPath('data.archived_at', null)->assertJsonPath('data.status', 'expected');
        $this->assertSame(5, $record->events()->count());
    }
}
