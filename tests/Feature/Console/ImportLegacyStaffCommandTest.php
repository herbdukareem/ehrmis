<?php

namespace Tests\Feature\Console;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Staff\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyStaffImportFixtures;
use Tests\TestCase;

class ImportLegacyStaffCommandTest extends TestCase
{
    use BuildsLegacyStaffImportFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLegacyStaffFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownLegacyStaffFixtures();
        parent::tearDown();
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this
            ->artisan('legacy:import-staff', [
                '--dry-run' => true,
                '--limit' => 100,
            ])
            ->assertSuccessful();

        $this->assertSame(0, LegacyStaffImportBatch::query()->count());
        $this->assertSame(0, LegacyStaffImportRow::query()->count());
        $this->assertSame(0, Staff::withoutGlobalScopes()->count());
    }

    public function test_command_can_stage_and_publish_staff_rows(): void
    {
        $this
            ->artisan('legacy:import-staff', [
                '--limit' => 100,
            ])
            ->assertSuccessful();

        $this->assertSame(1, LegacyStaffImportBatch::query()->count());
        $this->assertSame(4, LegacyStaffImportRow::query()->count());
        $this->assertSame(0, Staff::withoutGlobalScopes()->count());

        $this
            ->artisan('legacy:import-staff', [
                '--limit' => 100,
                '--publish' => true,
            ])
            ->assertSuccessful();

        $this->assertSame(4, Staff::withoutGlobalScopes()->count());
    }
}
