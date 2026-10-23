<?php

namespace Tests\Feature;

use App\Domain\ExecutiveOffice\Services\OfficeRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExecutiveOffice;
use Tests\TestCase;

class ExecutiveOfficeCorrespondenceTest extends TestCase
{
    use BuildsExecutiveOffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officeFixtures();
    }

    protected function createLetter(string $direction = 'outgoing')
    {
        return app(OfficeRecordService::class)->create($this->pa, $this->office, ['type' => 'correspondence', 'subject' => 'Letter', 'body' => 'Approved content', 'priority' => 'normal', 'confidentiality' => 'standard', 'details' => ['direction' => $direction, 'sender' => 'Our MDA', 'recipient' => 'Ministry']]);
    }

    public function test_outgoing_letter_requires_executive_approval_before_dispatch_and_preserves_the_approved_version(): void
    {
        $row = $this->createLetter();
        $url = $this->officeUrl('records/'.$row->id.'/actions');
        $this->actingAs($this->pa)->postJson($url, ['action' => 'dispatch', 'version' => 1, 'dispatch_method' => 'Courier', 'dispatch_recipient' => 'Ministry'])->assertForbidden();
        $this->postJson($url, ['action' => 'submit_decision', 'version' => 1, 'starts_at' => null, 'ends_at' => null, 'outcome' => null])->assertOk();
        $this->postJson($url, ['action' => 'approve', 'version' => 2])->assertForbidden();
        $this->actingAs($this->executive)->postJson($url, ['action' => 'approve', 'version' => 2])->assertOk()->assertJsonPath('data.approved_version', 3);
        $this->actingAs($this->pa)->putJson($this->officeUrl('records/'.$row->id), ['version' => 3])->assertUnprocessable();
        $this->postJson($url, ['action' => 'dispatch', 'version' => 3, 'dispatch_method' => 'Courier', 'dispatch_recipient' => 'Ministry', 'dispatch_evidence' => 'Acknowledged'])->assertOk()->assertJsonPath('data.status', 'dispatched')->assertJsonPath('data.details.dispatch_evidence', 'Acknowledged');
        $approved = $row->events()->where('action', 'approve')->firstOrFail();
        $this->assertSame('Approved content', $approved->snapshot['record']['body']);
        $this->assertSame(3, $approved->snapshot['record']['version']);
    }

    public function test_executive_can_refer_incoming_correspondence_and_only_the_assignee_can_respond(): void
    {
        $row = $this->createLetter('incoming');
        $url = $this->officeUrl('records/'.$row->id.'/actions');
        $this->actingAs($this->executive)->postJson($url, ['action' => 'refer', 'version' => 1, 'comment' => 'Provide figures', 'assigned_to' => $this->officer->id, 'due_at' => now()->addDays(2)->toIso8601String()])->assertOk()->assertJsonPath('data.status', 'assigned');
        $this->actingAs($this->pa)->postJson($url, ['action' => 'respond', 'version' => 2, 'comment' => 'Not my response'])->assertForbidden();
        $this->actingAs($this->officer)->postJson($url, ['action' => 'respond', 'version' => 2, 'comment' => 'Figures attached'])->assertOk()->assertJsonPath('data.status', 'response_submitted');
        $this->actingAs($this->pa)->postJson($url, ['action' => 'accept_response', 'version' => 3])->assertOk()->assertJsonPath('data.status', 'completed');
    }
}
