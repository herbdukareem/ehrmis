<?php

namespace Tests\Feature;

use App\Domain\Legacy\Services\LegacyMovementDataRepairService;
use App\Domain\Legacy\Services\LegacyStaffImportService;
use App\Domain\Staff\Models\PromotionPolicy;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsLegacyStaffImportFixtures;
use Tests\TestCase;

class LegacyMovementDataRepairTest extends TestCase
{
    use BuildsLegacyStaffImportFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLegacyStaffFixtures();

        Schema::connection('legacy')->create('promotion_years', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('scale');
            $table->unsignedTinyInteger('min_level');
            $table->unsignedTinyInteger('max_level');
            $table->unsignedTinyInteger('year');
            $table->string('status')->default('1');
        });
        DB::connection('legacy')->table('promotion_years')->insert([
            'scale' => 'GL',
            'min_level' => 1,
            'max_level' => 16,
            'year' => 3,
            'status' => '1',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownLegacyStaffFixtures();
        parent::tearDown();
    }

    public function test_it_restores_null_employment_dates_and_imports_promotion_policies(): void
    {
        app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'include_retired' => true,
            'publish' => true,
        ]);
        $staff = Staff::withoutGlobalScopes()->where('legacy_cno_psn', 'C001P001')->firstOrFail();
        $employment = StaffEmployment::query()->where('staff_id', $staff->id)->where('is_current', true)->firstOrFail();
        $employment->forceFill([
            'date_first_appointment' => null,
            'date_last_promotion' => null,
            'expected_retirement_date' => null,
            'next_promotion_date' => null,
        ])->save();
        PromotionPolicy::query()->delete();

        $summary = app(LegacyMovementDataRepairService::class)->repair();

        $employment->refresh();
        $this->assertSame('2010-01-01', $employment->date_first_appointment?->toDateString());
        $this->assertSame('2020-01-01', $employment->date_last_promotion?->toDateString());
        $this->assertSame('2040-01-01', $employment->expected_retirement_date?->toDateString());
        $this->assertSame('2023-01-01', $employment->next_promotion_date?->toDateString());
        $this->assertSame(1, $summary['promotion_policies_created']);
        $this->assertGreaterThan(0, $summary['employments_updated']);
    }
}
