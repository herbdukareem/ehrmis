<?php

namespace Tests\Feature\Console;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Services\LegacyStaffNumberGenerationService;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GenerateLegacyStaffNumbersTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;

    protected User $reviewer;

    protected LegacyStaffImportBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mda = Mda::factory()->create(['code' => 'HMB']);
        $this->reviewer = User::factory()->mdaUser($this->mda)->create();
        $this->reviewer->assignRole('MDA Admin');
        $this->batch = LegacyStaffImportBatch::query()->create([
            'source_database' => 'spreadsheet_upload',
            'source_table' => 'staff_list_upload',
            'created_by' => $this->reviewer->id,
            'status' => 'staged',
        ]);
    }

    public function test_command_resolves_missing_and_provisional_identifiers_with_audit_and_preserves_other_issues(): void
    {
        $missing = $this->row(['staff_number' => null, 'status' => 'invalid']);
        $provisional = $this->row();
        $existing = $this->row(['staff_number' => 'C10029', 'legacy_cno' => 'C10029']);
        $missingIssue = $this->issue($missing, 'missing_identifier', 'error');
        $provisionalIssue = $this->issue($provisional, 'provisional_identifier');
        $otherIssue = $this->issue($provisional, 'missing_department', 'error');
        $raw = $provisional->raw_payload;

        $this->artisan('legacy:generate-staff-numbers', ['batch_id' => $this->batch->id, '--user' => $this->reviewer->id])
            ->assertSuccessful();

        $this->assertStringStartsWith('SYS-HMB-', $missing->fresh()->staff_number);
        $provisional->refresh();
        $this->assertNotSame($missing->fresh()->staff_number, $provisional->staff_number);
        $this->assertSame($provisional->staff_number, $provisional->normalized_payload['staff_number']);
        $this->assertSame($provisional->staff_number, $provisional->dedupe_key);
        $this->assertSame('system_generated', $provisional->normalized_payload['staff_number_source']);
        $this->assertSame($raw, $provisional->raw_payload);
        $this->assertNull($provisional->legacy_cno);
        $this->assertNull($provisional->legacy_psn);
        $this->assertSame('C10029', $existing->fresh()->staff_number);
        $this->assertSame('staged', $missing->fresh()->status);
        $this->assertSame('invalid', $provisional->status);
        $this->assertNull($otherIssue->fresh()->resolved_at);
        $this->assertNotNull($missingIssue->fresh()->resolved_at);
        $this->assertSame($this->reviewer->id, $provisionalIssue->fresh()->resolved_by);
        $this->assertSame('generated_identifier_resolution', $provisionalIssue->fresh()->resolution_context['action']);
        $this->assertSame(2, AuditLog::query()->where('event_code', 'legacy_staff_import.identifier.generated')
            ->where('actor_user_id', $this->reviewer->id)->count());

        $numbers = $this->batch->rows()->pluck('staff_number', 'id')->all();
        $result = app(LegacyStaffNumberGenerationService::class)->generateBatch($this->batch, $this->reviewer);
        $this->assertSame(0, $result['generated']);
        $this->assertSame($numbers, $this->batch->rows()->pluck('staff_number', 'id')->all());
        $this->assertSame('staged', $this->batch->fresh()->status);
        $this->assertDatabaseCount('staff', 0);
    }

    public function test_preview_does_not_change_rows_or_resolve_warnings(): void
    {
        $row = $this->row();
        $issue = $this->issue($row, 'provisional_identifier');
        $this->artisan('legacy:generate-staff-numbers', [
            'batch_id' => $this->batch->id, '--user' => $this->reviewer->id, '--dry-run' => true,
        ])->expectsOutputToContain('No records were changed.')->assertSuccessful();

        $this->assertSame($row->staff_number, $row->fresh()->staff_number);
        $this->assertNull($issue->fresh()->resolved_at);
        $this->assertSame(0, AuditLog::query()->where('event_code', 'legacy_staff_import.identifier.generated')->count());
    }

    public function test_generation_respects_mda_and_batch_boundaries(): void
    {
        $own = $this->row();
        $other = $this->row(['mda_id' => Mda::factory()->create()->id]);
        $otherBatch = $this->batch->replicate();
        $otherBatch->save();
        $outsideBatch = $this->row(['batch_id' => $otherBatch->id]);

        $result = app(LegacyStaffNumberGenerationService::class)->generateBatch($this->batch, $this->reviewer);

        $this->assertSame(1, $result['generated']);
        $this->assertStringStartsWith('SYS-HMB-', $own->fresh()->staff_number);
        $this->assertSame($other->staff_number, $other->fresh()->staff_number);
        $this->assertSame($outsideBatch->staff_number, $outsideBatch->fresh()->staff_number);
    }

    public function test_generated_number_avoids_live_deleted_and_staged_collisions(): void
    {
        $row = $this->row();
        $base = 'SYS-HMB-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT);
        $this->staff($base);
        $deleted = $this->staff($base.'-2');
        $deleted->delete();
        $this->row(['staff_number' => $base.'-3']);

        app(LegacyStaffNumberGenerationService::class)->generateBatch($this->batch, $this->reviewer);

        $this->assertSame($base.'-4', $row->fresh()->staff_number);
    }

    public function test_published_matched_and_unmapped_rows_are_skipped(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $admin->assignRole('Super Admin');
        $staff = $this->staff('C123');
        $published = $this->row(['published_staff_id' => $staff->id, 'status' => 'published']);
        $matched = $this->row(['matched_staff_id' => $staff->id]);
        $unmapped = $this->row(['mda_id' => null]);

        $result = app(LegacyStaffNumberGenerationService::class)->generateBatch($this->batch, $admin);

        $this->assertSame(['eligible' => 0, 'generated' => 0, 'missing_mda' => 1, 'matched_staff' => 1], $result);
        foreach ([$published, $matched, $unmapped] as $row) {
            $this->assertSame($row->staff_number, $row->fresh()->staff_number);
        }
        $this->assertSame('C123', $staff->fresh()->staff_number);
    }

    public function test_user_without_resolution_permission_cannot_generate_numbers(): void
    {
        $viewer = User::factory()->mdaUser($this->mda, 'report_viewer')->create();
        $viewer->givePermissionTo('view-staff-imports');
        $this->row();
        $this->expectException(HttpException::class);

        app(LegacyStaffNumberGenerationService::class)->generateBatch($this->batch, $viewer);
    }

    public function test_user_cannot_generate_numbers_for_an_inaccessible_batch(): void
    {
        $otherUser = User::factory()->mdaUser(Mda::factory()->create())->create();
        $otherUser->assignRole('MDA Admin');
        $this->row();
        $this->expectException(AuthorizationException::class);

        app(LegacyStaffNumberGenerationService::class)->generateBatch($this->batch, $otherUser);
    }

    public function test_staging_batch_cannot_be_modified(): void
    {
        $this->batch->update(['status' => 'staging']);
        $this->row();
        $this->expectException(ValidationException::class);

        app(LegacyStaffNumberGenerationService::class)->generateBatch($this->batch, $this->reviewer);
    }

    protected function row(array $attributes = []): LegacyStaffImportRow
    {
        return LegacyStaffImportRow::query()->create(array_merge([
            'batch_id' => $this->batch->id,
            'mda_id' => $this->mda->id,
            'staff_number' => 'PROV-HMB-example',
            'full_name' => 'Test Officer',
            'raw_payload' => ['name' => 'Test Officer', 'cno' => 'NIL'],
            'normalized_payload' => ['mda_id' => $this->mda->id, 'staff_number' => 'PROV-HMB-example'],
            'status' => 'staged',
        ], $attributes));
    }

    protected function issue(LegacyStaffImportRow $row, string $code, string $severity = 'warning'): LegacyStaffImportError
    {
        return $row->errors()->create([
            'batch_id' => $row->batch_id, 'field' => 'staff_number',
            'error_code' => $code, 'message' => $code, 'severity' => $severity,
        ]);
    }

    protected function staff(string $number): Staff
    {
        return Staff::query()->create([
            'mda_id' => $this->mda->id, 'staff_number' => $number,
            'surname' => 'Officer', 'first_name' => 'Existing', 'full_name' => 'Existing Officer',
        ]);
    }
}
