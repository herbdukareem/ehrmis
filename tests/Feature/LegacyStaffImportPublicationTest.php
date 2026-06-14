<?php

namespace Tests\Feature;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Services\LegacyStaffImportApprovalService;
use App\Domain\Legacy\Services\LegacyStaffImportService;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyStaffImportFixtures;
use Tests\TestCase;

class LegacyStaffImportPublicationTest extends TestCase
{
    use BuildsLegacyStaffImportFixtures;
    use RefreshDatabase;

    protected LegacyStaffImportBatch $batch;
    protected User $mohPublisher;
    protected User $hrOfficer;
    protected User $globalPublisher;
    protected User $approvalOfficer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->setUpLegacyStaffFixtures();

        app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'include_retired' => true,
        ]);

        $this->batch = LegacyStaffImportBatch::query()->latest('id')->firstOrFail();

        $moh = Mda::query()->where('code', 'MOH')->firstOrFail();
        $this->mohPublisher = User::factory()->mdaUser($moh)->create();
        $this->mohPublisher->assignRole('MDA Admin');

        $this->hrOfficer = User::factory()->mdaUser($moh)->create();
        $this->hrOfficer->assignRole('HR Officer');

        $this->globalPublisher = User::factory()->create([
            'user_type' => \App\Enums\UserType::SUPER_ADMIN,
        ]);
        $this->globalPublisher->assignRole('Super Admin');

        $this->approvalOfficer = User::factory()->mdaUser($moh)->create();
        $this->approvalOfficer->assignRole('Approval Officer');
    }

    protected function tearDown(): void
    {
        $this->tearDownLegacyStaffFixtures();
        parent::tearDown();
    }

    public function test_unauthorized_user_cannot_publish(): void
    {
        $row = LegacyStaffImportRow::query()->where('mda_id', Mda::query()->where('code', 'MOH')->value('id'))->firstOrFail();

        $this->actingAs($this->hrOfficer)
            ->postJson(route('api.legacy-staff-imports.rows.publish', [$this->batch, $row]))
            ->assertForbidden();
    }

    public function test_authorized_user_can_publish_one_row(): void
    {
        $row = LegacyStaffImportRow::query()->where('legacy_cno_psn', 'C001P001')->firstOrFail();
        $this->submitAndApproveBatch();

        $this->actingAs($this->mohPublisher)
            ->postJson(route('api.legacy-staff-imports.rows.publish', [$this->batch, $row]))
            ->assertOk();

        $row->refresh();

        $this->assertSame('published', $row->status);
        $this->assertNotNull($row->published_staff_id);
        $this->assertDatabaseHas('staff', [
            'id' => $row->published_staff_id,
            'legacy_cno_psn' => 'C001P001',
        ]);
    }

    public function test_authorized_user_can_publish_batch_and_duplicate_publication_does_not_duplicate_staff(): void
    {
        $this->submitAndApproveBatch();

        $this->actingAs($this->mohPublisher)
            ->postJson(route('api.legacy-staff-imports.publish', $this->batch))
            ->assertOk();

        $firstCount = Staff::withoutGlobalScopes()->count();

        $this->actingAs($this->mohPublisher)
            ->postJson(route('api.legacy-staff-imports.publish', $this->batch))
            ->assertOk();

        $this->assertSame($firstCount, Staff::withoutGlobalScopes()->count());
    }

    public function test_batch_must_be_approved_before_publication(): void
    {
        $this->actingAs($this->mohPublisher)
            ->postJson(route('api.legacy-staff-imports.publish', $this->batch))
            ->assertForbidden();
    }

    public function test_rows_with_blocking_errors_are_skipped_during_batch_publication(): void
    {
        $this->submitAndApproveBatch($this->globalPublisher);

        $invalidRow = LegacyStaffImportRow::query()->create([
            'batch_id' => $this->batch->id,
            'legacy_staff_id' => 999,
            'mda_id' => null,
            'staff_number' => 'INVALID999',
            'legacy_cno' => 'INVALID',
            'legacy_psn' => '999',
            'legacy_cno_psn' => 'INVALID999',
            'full_name' => 'Invalid Blocking User',
            'raw_payload' => ['source_row' => ['mda' => null]],
            'normalized_payload' => [
                'staff_number' => 'INVALID999',
                'full_name' => 'Invalid Blocking User',
                'mda_id' => null,
            ],
            'dedupe_key' => 'INVALID999',
            'status' => 'invalid',
        ]);

        LegacyStaffImportError::query()->create([
            'batch_id' => $this->batch->id,
            'row_id' => $invalidRow->id,
            'field' => 'mda',
            'error_code' => 'missing_mda',
            'message' => 'MDA could not be resolved.',
            'severity' => 'error',
        ]);

        $this->actingAs($this->globalPublisher)
            ->postJson(route('api.legacy-staff-imports.publish', $this->batch))
            ->assertOk();

        $this->assertSame('invalid', $invalidRow->fresh()->status);
        $this->assertNull($invalidRow->fresh()->published_staff_id);
    }

    protected function submitAndApproveBatch(?User $submitter = null): void
    {
        $submitter ??= $this->mohPublisher;

        app(LegacyStaffImportApprovalService::class)->submitBatch($this->batch->fresh(), $submitter);
        app(LegacyStaffImportApprovalService::class)->approveBatch($this->batch->fresh(), $this->approvalOfficer, 'Approved for publication.');
        $this->batch->refresh();
    }
}
