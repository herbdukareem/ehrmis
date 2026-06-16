<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Services\LegacyStaffImportService;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyStaffImportFixtures;
use Tests\TestCase;

class LegacyStaffImportManagementTest extends TestCase
{
    use BuildsLegacyStaffImportFixtures;
    use RefreshDatabase;

    protected LegacyStaffImportBatch $batch;
    protected User $mohUser;
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

        $this->mohUser = User::factory()->mdaUser($moh)->create();
        $this->mohUser->assignRole('MDA Admin');

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

    public function test_import_batch_index_is_accessible_to_authorized_users(): void
    {
        $response = $this->actingAs($this->mohUser)
            ->getJson('/api/legacy-staff-imports');

        $response
            ->assertOk()
            ->assertJsonStructure(['data', 'meta', 'options']);
    }

    public function test_mda_user_sees_only_own_mda_import_rows(): void
    {
        $response = $this->actingAs($this->mohUser)
            ->getJson('/api/legacy-staff-imports/'.$this->batch->id);

        $rows = $response->json('data.rows');

        $this->assertNotEmpty($rows);
        $this->assertTrue(collect($rows)->every(fn ($row) => is_array($row)));
        $this->assertSame(['MOH'], collect($rows)->pluck('mda.code')->unique()->values()->all());
    }

    public function test_global_user_sees_all_import_batches(): void
    {
        app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'include_retired' => true,
            'source' => 'master_staff_list',
        ]);

        $response = $this->actingAs($this->globalUser)
            ->getJson('/api/legacy-staff-imports');

        $this->assertCount(2, $response->json('data'));
    }

    public function test_batch_rows_can_be_paginated_and_filtered_by_issue_severity(): void
    {
        $mohId = $this->mohUser->mda_id;

        $errorRow = null;

        foreach (range(1, 25) as $index) {
            $row = LegacyStaffImportRow::query()->create([
                'batch_id' => $this->batch->id,
                'mda_id' => $mohId,
                'staff_number' => 'PAGE-'.$index,
                'full_name' => 'Paging Officer '.$index,
                'raw_payload' => ['row' => $index],
                'normalized_payload' => ['mda_id' => $mohId, 'full_name' => 'Paging Officer '.$index],
                'dedupe_key' => 'PAGE-'.$index,
                'status' => 'staged',
            ]);

            $errorRow ??= $row;
        }

        LegacyStaffImportError::query()->create([
            'batch_id' => $this->batch->id,
            'row_id' => $errorRow->id,
            'field' => 'staff_number',
            'error_code' => 'known_test_error',
            'message' => 'Known blocking test error.',
            'severity' => 'error',
        ]);

        $page = $this->actingAs($this->mohUser)
            ->getJson('/api/legacy-staff-imports/'.$this->batch->id.'?per_page=20&page=2')
            ->assertOk();

        $this->assertSame(2, $page->json('meta.current_page'));
        $this->assertGreaterThan(20, $page->json('meta.total'));
        $this->assertNotEmpty($page->json('data.rows'));

        $errors = $this->actingAs($this->mohUser)
            ->getJson('/api/legacy-staff-imports/'.$this->batch->id.'?severity=error')
            ->assertOk();

        $this->assertNotEmpty($errors->json('data.rows'));
        $this->assertTrue(collect($errors->json('data.rows'))->every(
            fn (array $row): bool => ($row['issue_summary']['errors_count'] ?? 0) > 0
        ));
        $this->assertNotEmpty($errors->json('data.options.error_codes'));
    }

    public function test_batch_rows_per_page_is_capped_at_one_hundred(): void
    {
        $response = $this->actingAs($this->globalUser)
            ->getJson('/api/legacy-staff-imports/'.$this->batch->id.'?per_page=500')
            ->assertOk();

        $this->assertSame(100, $response->json('meta.per_page'));
    }

    public function test_warning_resolution_is_audited_and_raw_payload_is_not_modified(): void
    {
        $row = LegacyStaffImportRow::query()
            ->where('legacy_cno_psn', 'C003P003')
            ->firstOrFail();

        $warning = LegacyStaffImportError::query()
            ->where('row_id', $row->id)
            ->where('severity', 'warning')
            ->firstOrFail();

        $rawPayloadBefore = $row->raw_payload;

        $this->actingAs($this->mohUser)
            ->postJson(route('api.legacy-staff-imports.rows.ignore-warning', [$this->batch, $row]), [
                'warning_id' => $warning->id,
                'notes' => 'Reviewed by test',
            ])
            ->assertOk();

        $this->assertEquals($rawPayloadBefore, $row->fresh()->raw_payload);
        $this->assertNotNull($warning->fresh()->ignored_at);
        $this->assertTrue(
            AuditLog::query()
                ->where('event_code', 'legacy_staff_import.warning.ignored')
                ->exists()
        );
    }

    public function test_normalized_payload_can_be_corrected_safely(): void
    {
        $row = LegacyStaffImportRow::query()
            ->where('legacy_cno_psn', 'C003P003')
            ->firstOrFail();

        $stationId = \App\Domain\Organization\Models\Station::withoutGlobalScopes()
            ->where('name', 'MOH HQTR')
            ->value('id');

        $beforeNormalized = $row->normalized_payload;
        $beforeRaw = $row->raw_payload;

        $this->actingAs($this->mohUser)
            ->postJson(route('api.legacy-staff-imports.rows.resolve-mapping', [$this->batch, $row]), [
                'field' => 'station',
                'target_id' => $stationId,
                'notes' => 'Mapped for verification',
            ])
            ->assertOk();

        $row->refresh();

        $this->assertEquals($beforeRaw, $row->raw_payload);
        $this->assertNotSame($beforeNormalized['station_id'] ?? null, $row->normalized_payload['station_id'] ?? null);
        $this->assertSame($stationId, $row->station_id);
        $this->assertTrue(
            AuditLog::query()
                ->where('event_code', 'legacy_staff_import.mapping.resolved')
                ->exists()
        );
    }

    public function test_missing_staff_identifier_can_be_resolved_safely(): void
    {
        $row = LegacyStaffImportRow::query()->create([
            'batch_id' => $this->batch->id,
            'mda_id' => $this->mohUser->mda_id,
            'full_name' => 'Officer Without Number',
            'raw_payload' => ['name' => 'Officer Without Number'],
            'normalized_payload' => ['mda_id' => $this->mohUser->mda_id, 'full_name' => 'Officer Without Number'],
            'dedupe_key' => 'OFFICER_WITHOUT_NUMBER',
            'status' => 'invalid',
        ]);
        $error = LegacyStaffImportError::query()->create([
            'batch_id' => $this->batch->id,
            'row_id' => $row->id,
            'field' => 'staff_number',
            'error_code' => 'missing_identifier',
            'message' => 'Staff row has no usable identifier.',
            'severity' => 'error',
        ]);
        $rawPayloadBefore = $row->raw_payload;

        $this->actingAs($this->mohUser)
            ->postJson(route('api.legacy-staff-imports.rows.resolve-identifier', [$this->batch, $row]), [
                'staff_number' => 'MOH-VERIFIED-001',
                'notes' => 'Verified from personnel file',
            ])
            ->assertOk();

        $row->refresh();
        $this->assertSame('MOH-VERIFIED-001', $row->staff_number);
        $this->assertSame('MOH-VERIFIED-001', $row->normalized_payload['staff_number']);
        $this->assertSame($rawPayloadBefore, $row->raw_payload);
        $this->assertSame('staged', $row->status);
        $this->assertNotNull($error->fresh()->resolved_at);
        $this->assertTrue(AuditLog::query()->where('event_code', 'legacy_staff_import.identifier.resolved')->exists());

        $response = $this->actingAs($this->mohUser)
            ->getJson(route('api.legacy-staff-imports.rows.show', [$this->batch, $row]))
            ->assertOk();

        $this->assertSame(0, $response->json('data.row.issue_summary.errors_count'));
        $this->assertEmpty($response->json('data.row.errors'));
        $this->assertCount(1, $response->json('data.row.reviewed_issues'));
    }

}
