<?php

namespace Tests\Feature;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffPersonalDetailsImportService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StaffPersonalDetailsImportTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;

    protected User $actor;

    protected StaffPersonalDetailsImportService $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mda = Mda::factory()->create(['code' => 'HMB', 'name' => 'Hospital Management Board']);
        $this->actor = User::factory()->superAdmin()->create();
        $this->actor->assignRole('Super Admin');
        $this->actingAs($this->actor);
        $this->importer = app(StaffPersonalDetailsImportService::class);
    }

    public function test_import_preserves_identifiers_and_other_details_and_generates_reserved_unique_numbers_idempotently(): void
    {
        $foreign = $this->person('OTHER', ['mda_id' => Mda::factory()->create()->id]);
        $foreign->personalDetail()->create(['file_no' => 'G001', 'lga' => 'FOREIGN']);
        $numbered = $this->person('NUMBERED');
        $numbered->personalDetail()->create(['phone' => '08012345678']);
        $missing = $this->person('MISSING');
        $reserved = $this->person('RESERVED');
        $manual = $this->person('MANUAL');
        $manual->personalDetail()->create(['file_no' => 'MANUAL-001', 'lga' => 'LAPAI']);
        $rows = [
            $this->row($numbered, ['file_no' => '00123', 'lga' => 'Bida']),
            $this->row($missing, ['file_no' => '0', 'lga' => 'N/A']),
            $this->row($reserved, ['file_no' => 'G002']),
            $this->row($manual),
        ];
        $preview = $this->importer->import($this->mda, $this->actor, $rows, [], true);
        $this->assertSame(3, $preview['updated']);
        $this->assertSame(1, $preview['generated_file_numbers']);
        $this->assertNull($missing->fresh()->personalDetail);
        $this->assertNull($numbered->fresh()->personalDetail->file_no);
        $this->assertDatabaseCount('audit_logs', 0);

        $result = $this->importer->import($this->mda, $this->actor, $rows, ['filename' => 'source.xlsx']);
        $this->assertSame(3, $result['updated']);
        $this->assertSame('00123', $numbered->fresh()->personalDetail->file_no);
        $this->assertSame('BIDA', $numbered->fresh()->personalDetail->lga);
        $this->assertSame('08012345678', $numbered->fresh()->personalDetail->phone);
        $this->assertSame('G003', $missing->fresh()->personalDetail->file_no);
        $this->assertNull($missing->fresh()->personalDetail->lga);
        $this->assertSame('G002', $reserved->fresh()->personalDetail->file_no);
        $this->assertSame('MANUAL-001', $manual->fresh()->personalDetail->file_no);
        $this->assertSame('LAPAI', $manual->fresh()->personalDetail->lga);
        $this->assertSame('FOREIGN', $foreign->fresh()->personalDetail->lga);
        $this->assertSame('Person NUMBERED', $numbered->fresh()->full_name);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'staff.personal_details_imported', 'actor_user_id' => $this->actor->id]);
        $repeat = $this->importer->import($this->mda, $this->actor, $rows, []);
        $this->assertSame(0, $repeat['updated']);
        $this->assertSame(0, $repeat['generated_file_numbers']);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->getJson('/api/staff/'.$numbered->id)->assertOk()
            ->assertJsonPath('data.personal_detail.file_no', '00123')
            ->assertJsonPath('data.personal_detail.lga', 'BIDA');
    }

    public function test_corrected_identifiers_can_match_a_published_source_record_without_reverting_identity_changes(): void
    {
        $person = $this->person('CORRECTED', ['full_name' => 'Corrected Name']);
        $batch = LegacyStaffImportBatch::query()->create(['source_database' => 'upload', 'source_table' => 'staff', 'status' => 'published']);
        LegacyStaffImportRow::query()->create([
            'batch_id' => $batch->id, 'mda_id' => $this->mda->id, 'published_staff_id' => $person->id,
            'status' => 'published', 'raw_payload' => ['source_row' => ['name' => 'Original Name', 'cno' => 'OLD-CNO', 'dob' => '1980-01-02']],
        ]);
        $result = $this->importer->import($this->mda, $this->actor, [
            $this->row($person, ['name' => 'Original Name', 'cno' => 'OLD-CNO', 'file_no' => '0027', 'lga' => 'Lavun']),
        ], []);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('CORRECTED', $person->fresh()->staff_number);
        $this->assertSame('Corrected Name', $person->fresh()->full_name);
        $this->assertSame('0027', $person->fresh()->personalDetail->file_no);
    }

    public function test_duplicate_values_use_the_current_birth_date_but_unresolved_conflicts_and_unmatched_rows_are_skipped(): void
    {
        $resolved = $this->person('RESOLVED');
        $conflicted = $this->person('CONFLICTED');
        $result = $this->importer->import($this->mda, $this->actor, [
            $this->row($resolved, ['row' => 2, 'dob' => '1989-04-23', 'file_no' => '3123', 'lga' => 'BIDA']),
            $this->row($resolved, ['row' => 3, 'file_no' => '75131', 'lga' => 'Bida']),
            $this->row($conflicted, ['row' => 4, 'file_no' => '111']),
            $this->row($conflicted, ['row' => 5, 'file_no' => '222']),
            ['row' => 6, 'name' => 'Unknown Person', 'cno' => 'UNKNOWN', 'file_no' => '333', 'lga' => 'LAPAI'],
        ], []);
        $this->assertSame(1, $result['updated']);
        $this->assertCount(1, $result['resolved_duplicates']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertCount(1, $result['unmatched_source_rows']);
        $this->assertSame('75131', $resolved->fresh()->personalDetail->file_no);
        $this->assertNull($conflicted->fresh()->personalDetail);
        $this->assertDatabaseCount('staff', 2);
    }

    public function test_import_rejects_rows_from_a_different_mda_before_saving_any_changes(): void
    {
        $person = $this->person('LOCAL');
        try {
            $this->importer->import($this->mda, $this->actor, [
                $this->row($person, ['file_no' => '123']),
                $this->row($person, ['mda' => 'OTHER MDA', 'file_no' => '456']),
            ], []);
            $this->fail('Expected MDA validation to reject this workbook.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('different MDA', $exception->getMessage());
        }
        $this->assertNull($person->fresh()->personalDetail);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_cross_mda_number_reservations_require_a_platform_administrator(): void
    {
        $actor = User::factory()->mdaUser($this->mda)->create();
        $actor->assignRole('MDA Admin');
        $this->expectException(HttpException::class);
        $this->importer->import($this->mda, $actor, [], []);
    }

    public function test_exhausted_three_digit_numbers_do_not_save_partial_updates(): void
    {
        $person = $this->person('MISSING');
        $rows = [];
        for ($number = 1; $number <= 999; $number++) {
            $rows[] = ['row' => $number + 1, 'name' => 'Unmatched source '.$number, 'file_no' => sprintf('G%03d', $number)];
        }
        $rows[] = $this->row($person);
        try {
            $this->importer->import($this->mda, $this->actor, $rows, []);
            $this->fail('Expected an exhausted number range to stop the import.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Not enough unused G001-G999', $exception->getMessage());
        }
        $this->assertNull($person->fresh()->personalDetail);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    protected function person(string $number, array $overrides = []): Staff
    {
        return Staff::query()->create(array_replace([
            'mda_id' => $this->mda->id, 'staff_number' => $number, 'legacy_cno' => $number,
            'surname' => 'Person', 'first_name' => $number, 'full_name' => 'Person '.$number,
            'date_of_birth' => '1997-08-10', 'status' => 'active',
        ], $overrides));
    }

    protected function row(Staff $person, array $overrides = []): array
    {
        return array_replace([
            'row' => 2, 'name' => $person->full_name, 'cno' => $person->legacy_cno, 'psn' => null,
            'dob' => $person->date_of_birth->format('Y-m-d'), 'mda' => 'HMB', 'file_no' => null, 'lga' => null,
        ], $overrides);
    }
}
