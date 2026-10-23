<?php

namespace Tests\Feature;

use App\Domain\ExecutiveOffice\Models\OfficeNotification;
use App\Domain\ExecutiveOffice\Services\OfficeRecordService;
use App\Domain\ExecutiveOffice\Services\OfficeWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExecutiveOffice;
use Tests\TestCase;

class ExecutiveOfficeDiaryTest extends TestCase
{
    use BuildsExecutiveOffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(7, 0));
        $this->officeFixtures();
    }

    protected function appointment(array $changes = [])
    {
        return app(OfficeRecordService::class)->create($this->executive, $this->office, array_replace_recursive([
            'type' => 'appointment', 'subject' => 'Routine appointment', 'priority' => 'normal', 'confidentiality' => 'standard',
            'starts_at' => '2026-10-26T10:00', 'ends_at' => '2026-10-26T11:00', 'details' => ['requester' => 'Guest', 'venue' => 'Office', 'sensitive' => false],
        ], $changes));
    }

    public function test_routine_confirmation_conflicts_sensitive_bookings_and_rescheduling(): void
    {
        $row = $this->appointment();
        $this->assertSame('09:00', $row->starts_at->format('H:i'));
        $this->actingAs($this->pa)->postJson($this->officeUrl('records/'.$row->id.'/actions'), ['action' => 'confirm', 'version' => 1])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $overlap = $this->appointment(['confidentiality' => 'executive_only']);
        $this->actingAs($this->executive)->postJson($this->officeUrl('records/'.$overlap->id.'/actions'), ['action' => 'confirm', 'version' => 1])->assertUnprocessable();
        $sensitive = $this->appointment(['starts_at' => '2026-10-26T12:00', 'ends_at' => '2026-10-26T13:00', 'details' => ['sensitive' => true]]);
        $this->actingAs($this->pa)->postJson($this->officeUrl('records/'.$sensitive->id.'/actions'), ['action' => 'confirm', 'version' => 1])->assertForbidden();
        $this->postJson($this->officeUrl('records/'.$row->id.'/actions'), ['action' => 'reschedule', 'version' => 2, 'comment' => 'New date', 'starts_at' => '2026-10-27T10:00', 'ends_at' => '2026-10-27T11:00'])->assertOk()->assertJsonPath('data.status', 'requested');
    }

    public function test_diary_masks_confidential_engagements_and_reminders_are_idempotent(): void
    {
        $row = $this->appointment(['subject' => 'Secret engagement', 'confidentiality' => 'executive_only', 'starts_at' => '2026-10-26T08:30', 'ends_at' => '2026-10-26T09:00']);
        app(OfficeWorkflowService::class)->act($this->executive, $this->office, $row, ['action' => 'confirm', 'version' => 1]);
        $this->actingAs($this->pa)->getJson($this->officeUrl('diary').'&date=2026-10-26&view=day')->assertOk()->assertJsonPath('data.events.0.subject', 'Unavailable')->assertJsonPath('data.events.0.id', null);
        $this->actingAs($this->executive);
        $this->artisan('executive-office:reminders', ['--mda' => $this->mda->id])->assertSuccessful();
        $count = OfficeNotification::query()->count();
        $this->assertGreaterThan(0, $count);
        $this->artisan('executive-office:reminders', ['--mda' => $this->mda->id])->assertSuccessful();
        $this->assertSame($count, OfficeNotification::query()->count());
        $this->actingAs($this->pa)->getJson($this->officeUrl('notifications'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_front_desk_can_receive_expected_visitors_without_reading_their_meeting_briefs(): void
    {
        $visitor = app(OfficeRecordService::class)->create($this->pa, $this->office, ['type' => 'visitor', 'subject' => 'Expected guest', 'priority' => 'normal', 'confidentiality' => 'standard', 'starts_at' => now()->addHour()->toIso8601String(),
            'details' => ['visitor_name' => 'Guest', 'purpose' => 'Visit', 'host' => 'Executive', 'walk_in' => false]]);
        $this->actingAs($this->registry)->postJson($this->officeUrl('records/'.$visitor->id.'/actions'), ['action' => 'arrive', 'version' => 1])->assertOk()->assertJsonPath('data.status', 'arrived');
        $this->postJson($this->officeUrl('records/'.$visitor->id.'/actions'), ['action' => 'depart', 'version' => 2, 'outcome' => 'Visit complete', 'received_by_executive' => true])->assertOk()->assertJsonPath('data.details.received_by_executive', true);
        $this->getJson($this->officeUrl('diary').'&date=2026-10-26&view=week')->assertForbidden();
    }
}
