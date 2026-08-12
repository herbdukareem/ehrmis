<?php

namespace Tests\Feature;

use App\Domain\Posting\Models\StaffPostingLetter;
use App\Domain\Posting\Models\StaffPostingRequest;
use App\Domain\Posting\Services\StaffPostingWorkflowService;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class StaffPostingWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_submitted_and_origin_approved_requests_to_the_previous_stage(): void
    {
        $service = app(StaffPostingWorkflowService::class);
        $actor = User::factory()->create();
        $mda = Mda::factory()->create();

        $submitted = $this->makePostingRequest($mda, $mda, 'submitted', $actor);
        $draft = $service->revertToPreviousStage($submitted, $actor, 'Return to draft');
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->submitted_at);
        $this->assertSame('submission', $draft->approvals->last()->stage);
        $this->assertSame('reverted', $draft->approvals->last()->decision);

        $approvedByOrigin = $this->makePostingRequest($mda, $mda, 'from_mda_approved', $actor);
        $submittedAgain = $service->revertToPreviousStage($approvedByOrigin, $actor, 'Back to origin review');
        $this->assertSame('submitted', $submittedAgain->status);
        $this->assertSame('origin_mda', $submittedAgain->approvals->last()->stage);
        $this->assertSame('reverted', $submittedAgain->approvals->last()->decision);
    }

    public function test_it_returns_inter_mda_requests_one_stage_back_at_a_time(): void
    {
        $service = app(StaffPostingWorkflowService::class);
        $actor = User::factory()->create();
        $fromMda = Mda::factory()->create();
        $toMda = Mda::factory()->create();

        $receivingApproved = $this->makePostingRequest($fromMda, $toMda, 'receiving_mda_approved', $actor);
        $originApproved = $service->revertToPreviousStage($receivingApproved, $actor, 'Send back to receiving approval');
        $this->assertSame('from_mda_approved', $originApproved->status);
        $this->assertSame('receiving_mda', $originApproved->approvals->last()->stage);

        $finalApproved = $this->makePostingRequest($fromMda, $toMda, 'approved', $actor);
        $backToReceiving = $service->revertToPreviousStage($finalApproved, $actor, 'Return to final review');
        $this->assertSame('receiving_mda_approved', $backToReceiving->status);
        $this->assertSame('final', $backToReceiving->approvals->last()->stage);
        $this->assertSame('reverted', $backToReceiving->approvals->last()->decision);
    }

    public function test_it_returns_issued_requests_to_approved_and_revokes_the_letter(): void
    {
        $service = app(StaffPostingWorkflowService::class);
        $actor = User::factory()->create();
        $mda = Mda::factory()->create();

        $issued = $this->makePostingRequest($mda, $mda, 'issued', $actor);
        $letter = StaffPostingLetter::query()->create([
            'posting_request_id' => $issued->id,
            'letter_number' => 'PT-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'status' => 'printed',
            'pdf_path' => 'letters/posting-test.pdf',
            'generated_by' => $actor->id,
            'generated_at' => now()->subMinute(),
            'printed_by' => $actor->id,
            'printed_at' => now(),
        ]);

        $approved = $service->revertToPreviousStage($issued, $actor, 'Undo issued stage');

        $this->assertSame('approved', $approved->status);
        $this->assertNull($approved->issued_at);
        $this->assertNull($approved->issued_by);
        $this->assertSame('letter', $approved->approvals->last()->stage);

        $letter->refresh();
        $this->assertSame('revoked', $letter->status);
        $this->assertNull($letter->pdf_path);
        $this->assertNull($letter->printed_at);
        $this->assertNull($letter->printed_by);
    }

    public function test_it_rejects_reverting_effected_requests(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $service = app(StaffPostingWorkflowService::class);
        $actor = User::factory()->create();
        $mda = Mda::factory()->create();

        $effected = $this->makePostingRequest($mda, $mda, 'effected', $actor);

        $service->revertToPreviousStage($effected, $actor);
    }

    protected function makePostingRequest(Mda $fromMda, Mda $toMda, string $status, User $actor): StaffPostingRequest
    {
        $staff = Staff::query()->create([
            'mda_id' => $fromMda->id,
            'staff_number' => 'STF-'.Str::upper(Str::random(6)),
            'surname' => 'Test',
            'first_name' => 'Officer',
            'full_name' => 'Test Officer',
            'status' => 'active',
        ]);

        return StaffPostingRequest::query()->create([
            'staff_id' => $staff->id,
            'request_number' => 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'posting_type' => $fromMda->is($toMda) ? 'department_transfer' : 'inter_mda_transfer',
            'from_mda_id' => $fromMda->id,
            'to_mda_id' => $toMda->id,
            'effective_date' => now()->toDateString(),
            'staff_snapshot' => ['staff_id' => $staff->id],
            'status' => $status,
            'requested_by' => $actor->id,
            'submitted_at' => in_array($status, ['submitted', 'from_mda_approved', 'receiving_mda_approved', 'approved', 'issued', 'effected'], true) ? now()->subHour() : null,
            'issued_by' => in_array($status, ['issued', 'effected'], true) ? $actor->id : null,
            'issued_at' => in_array($status, ['issued', 'effected'], true) ? now()->subMinutes(20) : null,
            'effected_by' => $status === 'effected' ? $actor->id : null,
            'effected_at' => $status === 'effected' ? now()->subMinutes(5) : null,
        ]);
    }
}
