<?php

namespace Tests\Feature;

use App\Domain\ExecutiveOffice\Services\OfficeRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExecutiveOffice;
use Tests\TestCase;

class ExecutiveOfficeMeetingsTest extends TestCase
{
    use BuildsExecutiveOffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officeFixtures();
    }

    public function test_participants_can_respond_and_minutes_require_executive_approval(): void
    {
        $input = ['type' => 'meeting', 'subject' => 'Review meeting', 'priority' => 'normal', 'confidentiality' => 'standard', 'starts_at' => '2026-10-26T10:00', 'ends_at' => '2026-10-26T11:00', 'details' => ['venue' => 'Office', 'agenda' => 'Review', 'participant_user_ids' => [$this->officer->id], 'minutes' => 'Decisions recorded']];
        $row = app(OfficeRecordService::class)->create($this->pa, $this->office, $input);
        $url = $this->officeUrl('records/'.$row->id.'/actions');
        $this->actingAs($this->pa)->postJson($url, ['action' => 'confirm', 'version' => 1])->assertOk();
        $this->actingAs($this->officer)->getJson($this->officeUrl('records/'.$row->id))->assertOk()->assertJsonCount(1, 'data.participants');
        $this->postJson($url, ['action' => 'rsvp_accept', 'version' => 2])->assertOk()->assertJsonPath('data.participants.0.response', 'Accepted');
        $this->actingAs($this->pa)->postJson($url, ['action' => 'record_attendance', 'version' => 3, 'attended_user_ids' => [$this->officer->id]])->assertOk()->assertJsonPath('data.participants.0.attendance', 'Present');
        $this->postJson($url, ['action' => 'submit_minutes', 'version' => 4])->assertOk();
        $this->postJson($url, ['action' => 'approve_minutes', 'version' => 5])->assertForbidden();
        $this->actingAs($this->executive)->postJson($url, ['action' => 'approve_minutes', 'version' => 5])->assertOk()->assertJsonPath('data.approved_version', 6);
        $this->actingAs($this->pa)->putJson($this->officeUrl('records/'.$row->id), $input + ['version' => 6])->assertUnprocessable();
    }

    public function test_appointment_converts_to_meeting_without_duplicate_diary_booking(): void
    {
        $row = app(OfficeRecordService::class)->create($this->pa, $this->office, ['type' => 'appointment', 'subject' => 'Visit', 'priority' => 'normal', 'confidentiality' => 'standard', 'starts_at' => '2026-10-26T10:00', 'ends_at' => '2026-10-26T11:00', 'details' => ['requester' => 'Guest', 'venue' => 'Office', 'sensitive' => false]]);
        $url = $this->officeUrl('records/'.$row->id.'/actions');
        $this->actingAs($this->pa)->postJson($url, ['action' => 'confirm', 'version' => 1])->assertOk();
        $this->postJson($url, ['action' => 'convert_to_meeting', 'version' => 2, 'agenda' => 'Discussion'])->assertOk()->assertJsonPath('data.type', 'meeting')->assertJsonPath('data.status', 'confirmed');
        $this->getJson($this->officeUrl('diary').'&date=2026-10-26&view=week')->assertOk()->assertJsonCount(1, 'data.events');
    }
}
