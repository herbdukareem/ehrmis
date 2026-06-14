<?php

namespace Tests\Feature;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Services\LegacyStaffImportService;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyStaffImportFixtures;
use Tests\TestCase;

class LegacyStaffImportApprovalTest extends TestCase
{
    use BuildsLegacyStaffImportFixtures;
    use RefreshDatabase;

    protected LegacyStaffImportBatch $batch;
    protected User $reviewer;
    protected User $approver;

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

        $this->reviewer = User::factory()->mdaUser($moh)->create();
        $this->reviewer->assignRole('MDA Admin');

        $this->approver = User::factory()->mdaUser($moh)->create();
        $this->approver->assignRole('Approval Officer');
    }

    protected function tearDown(): void
    {
        $this->tearDownLegacyStaffFixtures();
        parent::tearDown();
    }

    public function test_batch_can_be_submitted_and_approved_via_http_routes(): void
    {
        $this->actingAs($this->reviewer)
            ->postJson(route('api.legacy-staff-imports.submit', $this->batch))
            ->assertOk();

        $this->assertSame('submitted', $this->batch->fresh()->status);
        $this->assertSame('submitted', $this->batch->fresh()->approvalWorkflow?->status);

        $this->actingAs($this->approver)
            ->postJson(route('api.legacy-staff-imports.approve', $this->batch), [
                'comment' => 'Ready for controlled publication.',
            ])
            ->assertOk();

        $this->assertSame('approved', $this->batch->fresh()->status);
        $this->assertSame('approved', $this->batch->fresh()->approvalWorkflow?->status);
    }
}
