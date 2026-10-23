<?php

namespace Tests\Feature;

use App\Domain\ExecutiveOffice\Models\OfficeRecord;
use App\Domain\ExecutiveOffice\Services\OfficeRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExecutiveOffice;
use Tests\TestCase;

class ExecutiveOfficeDirectivesTest extends TestCase
{
    use BuildsExecutiveOffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officeFixtures();
    }

    protected function directive(): array
    {
        return ['type' => 'directive', 'subject' => 'Prepare figures', 'priority' => 'high', 'confidentiality' => 'standard', 'assigned_to' => $this->officer->id, 'due_at' => now()->addDay()->toIso8601String(), 'details' => ['instruction' => 'Provide the quarterly totals.']];
    }

    public function test_pa_drafts_are_not_instructions_and_response_submission_is_not_completion(): void
    {
        $row = app(OfficeRecordService::class)->create($this->pa, $this->office, $this->directive());
        $url = $this->officeUrl('records/'.$row->id.'/actions');
        $this->actingAs($this->officer)->getJson($this->officeUrl('records/'.$row->id))->assertNotFound();
        $this->actingAs($this->pa)->postJson($url, ['action' => 'issue', 'version' => 1])->assertForbidden();
        $this->actingAs($this->executive)->postJson($url, ['action' => 'issue', 'version' => 1])->assertOk();
        $this->actingAs($this->officer)->postJson($url, ['action' => 'acknowledge', 'version' => 2])->assertOk();
        $this->postJson($url, ['action' => 'submit_response', 'version' => 3, 'comment' => 'Figures attached'])->assertOk()->assertJsonPath('data.status', 'response_submitted')->assertJsonPath('data.closed_at', null);
        $this->postJson($url, ['action' => 'accept_response', 'version' => 4])->assertForbidden();
        $this->actingAs($this->pa)->postJson($url, ['action' => 'return_response', 'version' => 4, 'comment' => 'Include missing month'])->assertOk()->assertJsonPath('data.status', 'returned');
        $this->actingAs($this->officer)->postJson($url, ['action' => 'submit_response', 'version' => 5, 'comment' => 'Corrected'])->assertOk();
        $this->actingAs($this->executive)->postJson($url, ['action' => 'accept_response', 'version' => 6])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_one_decision_can_issue_multiple_actions_without_exposing_its_confidential_source(): void
    {
        $source = app(OfficeRecordService::class)->create($this->executive, $this->office, ['type' => 'correspondence', 'subject' => 'Secret source', 'priority' => 'normal', 'confidentiality' => 'executive_only', 'details' => ['direction' => 'incoming', 'sender' => 'Executive']]);
        $result = $this->actingAs($this->executive)->postJson($this->officeUrl('records/'.$source->id.'/directives'), ['version' => 1, 'actions' => [$this->directive(), $this->directive()]])->assertCreated()->assertJsonCount(2, 'data');
        $child = $result->json('data.0.id');
        $this->actingAs($this->officer)->getJson($this->officeUrl('records/'.$child))->assertOk()->assertJsonPath('data.status', 'assigned')->assertJsonCount(0, 'data.links')->assertJsonMissing(['subject' => 'Secret source']);
        $this->actingAs($this->executive)->postJson($this->officeUrl('records/'.$source->id.'/directives'), ['version' => 1, 'actions' => [$this->directive()]])->assertStatus(409);
        $this->assertSame(2, OfficeRecord::query()->where('office_id', $this->office->id)->where('type', 'directive')->count());
    }
}
