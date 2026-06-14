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

class LegacyStaffImportPolicyTest extends TestCase
{
    use BuildsLegacyStaffImportFixtures;
    use RefreshDatabase;

    protected LegacyStaffImportBatch $batch;
    protected User $mohUser;
    protected User $hmbUser;
    protected User $globalUser;

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
        $hmb = Mda::query()->where('code', 'HMB')->firstOrFail();

        $this->mohUser = User::factory()->mdaUser($moh)->create();
        $this->mohUser->assignRole('MDA Admin');

        $this->hmbUser = User::factory()->mdaUser($hmb)->create();
        $this->hmbUser->assignRole('MDA Admin');

        $this->globalUser = User::factory()->create([
            'user_type' => \App\Enums\UserType::SUPER_ADMIN,
        ]);
        $this->globalUser->assignRole('Super Admin');
    }

    protected function tearDown(): void
    {
        $this->tearDownLegacyStaffFixtures();
        parent::tearDown();
    }

    public function test_staff_import_policies_enforce_mda_scope_and_global_access(): void
    {
        $this->actingAs($this->mohUser)
            ->getJson(route('api.legacy-staff-imports.show', $this->batch))
            ->assertOk();

        $row = $this->batch->rows()->where('mda_id', $this->hmbUser->mda_id)->firstOrFail();

        $this->actingAs($this->mohUser)
            ->getJson(route('api.legacy-staff-imports.rows.show', [$this->batch, $row]))
            ->assertForbidden();

        $this->actingAs($this->globalUser)
            ->getJson(route('api.legacy-staff-imports.rows.show', [$this->batch, $row]))
            ->assertOk();
    }
}
