<?php

namespace Tests\Feature;

use App\Domain\ExecutiveOffice\Services\OfficeRecordService;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsExecutiveOffice;
use Tests\TestCase;

class ExecutiveOfficeRecordsTest extends TestCase
{
    use BuildsExecutiveOffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officeFixtures();
    }

    protected function input(array $changes = []): array
    {
        return array_replace_recursive(['type' => 'correspondence', 'subject' => 'Official letter', 'body' => 'Contents', 'priority' => 'normal', 'confidentiality' => 'standard', 'details' => ['direction' => 'incoming', 'sender' => 'Ministry']], $changes);
    }

    public function test_confidentiality_notes_and_admin_access_are_enforced_on_lists_and_direct_requests(): void
    {
        $service = app(OfficeRecordService::class);
        $standard = $service->create($this->executive, $this->office, $this->input(['assigned_to' => $this->officer->id]));
        $restricted = $service->create($this->executive, $this->office, $this->input(['confidentiality' => 'office_restricted']));
        $secret = $service->create($this->executive, $this->office, $this->input(['confidentiality' => 'executive_only']));
        $service->note($this->executive, $this->office, $standard, ['body' => 'For executive only', 'visibility' => 'executive_only']);
        $service->note($this->pa, $this->office, $standard, ['body' => 'PA preparation', 'visibility' => 'office']);
        $service->note($this->executive, $this->office, $standard, ['body' => 'Please respond', 'visibility' => 'recipients']);
        $this->actingAs($this->pa)->getJson($this->officeUrl('records'))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->officeUrl('records/'.$secret->id))->assertNotFound();
        $this->getJson($this->officeUrl('records/'.$standard->id))->assertOk()->assertJsonCount(2, 'data.notes')->assertJsonMissing(['body' => 'For executive only']);
        $this->actingAs($this->officer)->getJson($this->officeUrl('records/'.$standard->id))->assertOk()->assertJsonCount(1, 'data.notes')->assertJsonPath('data.notes.0.body', 'Please respond');
        $this->getJson($this->officeUrl('records/'.$restricted->id))->assertNotFound();
        $this->actingAs($this->admin)->getJson($this->officeUrl('records'))->assertForbidden();
    }

    public function test_private_attachments_require_current_parent_access_and_visibility(): void
    {
        Storage::fake('local');
        $record = app(OfficeRecordService::class)->create($this->executive, $this->office, $this->input(['assigned_to' => $this->officer->id]));
        $response = $this->actingAs($this->executive)->post($this->officeUrl('records/'.$record->id.'/attachments'), ['version' => 1, 'visibility' => 'executive_only', 'file' => UploadedFile::fake()->create('private.pdf', 10, 'application/pdf')])->assertCreated();
        $id = $response->json('data.id');
        $this->assertArrayNotHasKey('path', $response->json('data'));
        $this->actingAs($this->pa)->get($this->officeUrl('records/'.$record->id.'/attachments/'.$id))->assertNotFound();
        $this->actingAs($this->executive)->get($this->officeUrl('records/'.$record->id.'/attachments/'.$id))->assertOk();
        $this->get($this->officeUrl('records/'.$record->id.'/attachments/'.$id.'/preview'))->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('cache-control', 'no-store, private');
        $this->office->memberships()->where('user_id', $this->executive->id)->update(['revoked_at' => now()]);
        $this->get($this->officeUrl('records/'.$record->id.'/attachments/'.$id))->assertForbidden();
    }

    public function test_versions_links_and_tenant_isolation_are_enforced(): void
    {
        $first = $this->actingAs($this->pa)->postJson($this->officeUrl('records'), $this->input())->assertCreated()->json('data');
        $second = $this->postJson($this->officeUrl('records'), $this->input(['subject' => 'Response']))->assertCreated()->json('data');
        $this->assertNotSame($first['reference'], $second['reference']);
        $this->putJson($this->officeUrl('records/'.$first['id']), $this->input(['version' => 1, 'subject' => 'Revised']))->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($this->officeUrl('records/'.$first['id']), $this->input(['version' => 1]))->assertStatus(409);
        $this->postJson($this->officeUrl('records/'.$first['id'].'/links'), ['target_id' => $second['id']])->assertCreated();
        $this->getJson($this->officeUrl('records/'.$first['id']))->assertOk()->assertJsonCount(1, 'data.links')->assertJsonPath('data.events.0.action', 'linked');
        $other = User::factory()->mdaUser(Mda::factory()->create())->create();
        $other->givePermissionTo('view-executive-office');
        $this->actingAs($other)->getJson($this->officeUrl('records/'.$first['id']))->assertForbidden();
    }
}
