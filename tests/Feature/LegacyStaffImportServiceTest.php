<?php

namespace Tests\Feature;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportPublication;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Services\LegacyStaffImportService;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffPersonalDetail;
use App\Domain\Staff\Models\StaffQualification;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Domain\Staff\Models\StaffStatusHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyStaffImportFixtures;
use Tests\TestCase;

class LegacyStaffImportServiceTest extends TestCase
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

    public function test_staging_writes_raw_and_normalized_rows(): void
    {
        $summary = app(LegacyStaffImportService::class)->import([
            'limit' => 100,
        ]);

        $this->assertSame(4, $summary['rows_read']);
        $this->assertSame(4, $summary['rows_staged']);
        $this->assertSame(0, $summary['rows_published']);
        $this->assertSame(1, LegacyStaffImportBatch::query()->count());
        $this->assertSame(4, LegacyStaffImportRow::query()->count());
        $this->assertGreaterThan(0, LegacyStaffImportError::query()->count());
        $this->assertNotNull(LegacyStaffImportRow::query()->first()?->raw_payload);
        $this->assertNotNull(LegacyStaffImportRow::query()->first()?->normalized_payload);
    }

    public function test_publishing_creates_staff_and_related_records(): void
    {
        $summary = app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'publish' => true,
        ]);

        $this->assertSame(4, $summary['rows_published']);
        $this->assertSame(4, Staff::withoutGlobalScopes()->count());
        $this->assertSame(4, StaffPersonalDetail::query()->count());
        $this->assertSame(4, StaffEmployment::query()->count());
        $this->assertSame(4, StaffSalaryPlacement::query()->count());
        $this->assertSame(4, StaffQualification::query()->count());
        $this->assertSame(25, StaffAllowanceAssignment::query()->count());
        $this->assertSame(1, LegacyStaffImportPublication::query()->count());

        $staff = Staff::withoutGlobalScopes()->where('legacy_cno_psn', 'C001P001')->firstOrFail();
        $callDoctorId = \App\Domain\Staff\Models\AllowanceType::query()
            ->forMda($staff->mda_id)
            ->where('code', 'call_doctor')
            ->value('id');
        $this->assertDatabaseHas('staff_allowance_assignments', [
            'staff_id' => $staff->id,
            'allowance_type_id' => $callDoctorId,
            'is_eligible' => true,
        ]);
    }

    public function test_rerunning_publish_does_not_duplicate_staff(): void
    {
        app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'publish' => true,
        ]);

        app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'publish' => true,
        ]);

        $this->assertSame(4, Staff::withoutGlobalScopes()->count());
        $this->assertSame(4, StaffEmployment::query()->count());
        $this->assertSame(4, StaffSalaryPlacement::query()->count());
    }

    public function test_retired_filters_behave_correctly(): void
    {
        $default = app(LegacyStaffImportService::class)->import(['limit' => 100]);
        $includeRetired = app(LegacyStaffImportService::class)->import(['limit' => 100, 'include_retired' => true]);
        $onlyRetired = app(LegacyStaffImportService::class)->import(['limit' => 100, 'only_retired' => true]);

        $this->assertSame(4, $default['rows_read']);
        $this->assertSame(0, $default['retired_staff']);
        $this->assertSame(5, $includeRetired['rows_read']);
        $this->assertSame(1, $includeRetired['retired_staff']);
        $this->assertSame(1, $onlyRetired['rows_read']);
        $this->assertSame(0, $onlyRetired['active_staff']);
        $this->assertSame(1, $onlyRetired['retired_staff']);
    }

    public function test_invalid_dates_missing_references_and_mismatches_create_warnings(): void
    {
        $summary = app(LegacyStaffImportService::class)->import([
            'limit' => 100,
        ]);

        $messages = LegacyStaffImportError::query()->pluck('message')->all();

        $this->assertSame(1, $summary['missing_department']);
        $this->assertSame(1, $summary['missing_station']);
        $this->assertSame(1, $summary['missing_cadre']);
        $this->assertSame(1, $summary['missing_rank']);
        $this->assertSame(1, $summary['edor_mismatch_count']);
        $this->assertSame(1, $summary['next_promotion_mismatch_count']);
        $this->assertTrue(collect($messages)->contains(fn ($message) => str_contains($message, 'Could not confidently parse date_of_birth')));
        $this->assertTrue(collect($messages)->contains(fn ($message) => str_contains($message, 'Department `UNKNOWN DEPARTMENT` could not be resolved.')));
        $this->assertTrue(collect($messages)->contains(fn ($message) => str_contains($message, 'Imported EDOR')));
    }

    public function test_call_allowance_is_resolved_from_direct_and_derived_legacy_signals(): void
    {
        DB::connection('legacy')->table('staff_list')->insert([
            'id' => 6,
            'cno' => 'C006',
            'name' => 'Derived Pharmacist User',
            'psn' => 'P006',
            'sex' => 'Female',
            'mda' => 'HOSPITAL MANAGEMENT BOARD',
            'station' => 'HMB HQTRS',
            'location' => 'MINNA',
            'salary_scale' => 'CH',
            'highest_qualification' => 'HND',
            'department' => 'ADMIN',
            'dob' => '1987-01-01',
            'dfa' => '2011-01-01',
            'dpa' => '2021-01-01',
            'edor' => '2047-01-01',
            'cadre' => 'PHARMACIST',
            'rank' => 'A.O II',
            'level' => 8,
            'step' => 2,
            'basic_salary' => 52000,
            'gross' => 58000,
            'cno_psn' => 'C006P006',
            'status' => '1',
        ]);

        DB::connection('legacy')->table('master_staff_list')->insert([
            'id' => 106,
            'psn' => 'P006',
            'cno' => 'C006',
            'first_name' => 'Derived',
            'other_name' => 'User',
            'surname' => 'Pharmacist',
            'date_of_birth' => '1987-01-01',
            'sex' => 'Female',
            'lga' => 'Bosso',
            'state' => 'Niger',
            'mda' => 'HOSPITAL MANAGEMENT BOARD',
            'department' => 'ADMIN',
            'station' => 'HMB HQTRS',
            'location' => 'MINNA',
            'date_of_first_appointment' => '2011-01-01',
            'date_of_last_promotion' => '2021-01-01',
            'date_of_retirement_by_age' => '2047-01-01',
            'cadre' => 'PHARMACIST',
            'rank' => 'A.O II',
            'highest_qualification' => 'HND',
            'salary_scale' => 'CONHESS',
            'salary_scale_code' => 'CH',
            'level' => '8',
            'step' => 2,
            'call_allowance' => 'YES',
            'status' => '1',
        ]);

        $summary = app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'include_retired' => true,
            'publish' => true,
        ]);

        $this->assertSame(6, $summary['rows_published']);
        $this->assertSame(2, $summary['call_allowance_resolved']);
        $this->assertSame(0, $summary['call_allowance_unresolved']);

        $doctor = Staff::withoutGlobalScopes()->where('legacy_cno_psn', 'C001P001')->firstOrFail();
        $pharmacist = Staff::withoutGlobalScopes()->where('legacy_cno_psn', 'C006P006')->firstOrFail();

        $this->assertDatabaseHas('staff_allowance_assignments', [
            'staff_id' => $doctor->id,
            'allowance_type_id' => \App\Domain\Staff\Models\AllowanceType::query()
                ->forMda($doctor->mda_id)
                ->where('code', 'call_doctor')
                ->value('id'),
            'is_eligible' => true,
        ]);

        $this->assertDatabaseHas('staff_allowance_assignments', [
            'staff_id' => $pharmacist->id,
            'allowance_type_id' => \App\Domain\Staff\Models\AllowanceType::query()
                ->forMda($pharmacist->mda_id)
                ->where('code', 'call_pharm_lab')
                ->value('id'),
            'is_eligible' => true,
        ]);
    }

    public function test_nursing_rank_alias_and_call_allowance_are_resolved_from_unique_context(): void
    {
        $hmb = Mda::query()->where('code', 'HMB')->firstOrFail();
        $hmbAdminDepartmentId = \App\Domain\Organization\Models\Department::query()
            ->where('name', 'ADMIN')
            ->where('mda_id', $hmb->id)
            ->value('id');
        $hmbCh = \App\Domain\Staff\Models\SalaryScale::query()
            ->forMda($hmb->id)
            ->where('code', 'CH')
            ->firstOrFail();

        $nursingCadre = Cadre::query()->create([
            'salary_scale_id' => $hmbCh->id,
            'department_id' => $hmbAdminDepartmentId,
            'name' => 'NURSING',
            'legacy_department_name' => 'ADMIN',
            'status' => 'active',
        ]);

        $nursingRank = Rank::query()->create([
            'cadre_id' => $nursingCadre->id,
            'salary_scale_id' => $hmbCh->id,
            'name' => 'Nursing Supritendent',
            'level' => 6,
            'status' => 'active',
        ]);

        DB::connection('legacy')->table('staff_list')->insert([
            'id' => 6,
            'cno' => 'C006',
            'name' => 'Nursing Alias User',
            'psn' => 'P006',
            'sex' => 'Female',
            'mda' => 'HOSPITAL MANAGEMENT BOARD',
            'station' => 'HMB HQTRS',
            'location' => 'MINNA',
            'salary_scale' => 'CH',
            'highest_qualification' => 'HND',
            'department' => 'ADMIN',
            'dob' => '1987-01-01',
            'dfa' => '2011-01-01',
            'dpa' => '2021-01-01',
            'edor' => '2047-01-01',
            'cadre' => 'NURSING',
            'rank' => 'SN',
            'level' => 6,
            'step' => 2,
            'basic_salary' => 52000,
            'gross' => 58000,
            'cno_psn' => 'C006P006',
            'status' => '1',
        ]);

        DB::connection('legacy')->table('master_staff_list')->insert([
            'id' => 106,
            'psn' => 'P006',
            'cno' => 'C006',
            'first_name' => 'Nursing',
            'other_name' => 'Alias',
            'surname' => 'User',
            'date_of_birth' => '1987-01-01',
            'sex' => 'Female',
            'lga' => 'Bosso',
            'state' => 'Niger',
            'mda' => 'HOSPITAL MANAGEMENT BOARD',
            'department' => 'ADMIN',
            'station' => 'HMB HQTRS',
            'location' => 'MINNA',
            'date_of_first_appointment' => '2011-01-01',
            'date_of_last_promotion' => '2021-01-01',
            'date_of_retirement_by_age' => '2047-01-01',
            'cadre' => 'NURSING',
            'rank' => 'SN',
            'highest_qualification' => 'HND',
            'salary_scale' => 'CONHESS',
            'salary_scale_code' => 'CH',
            'level' => '6',
            'step' => 2,
            'call_allowance' => 'YES',
            'status' => '1',
        ]);

        $summary = app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'publish' => true,
        ]);

        $this->assertSame(5, $summary['rows_published']);
        $this->assertSame(0, $summary['missing_rank']);
        $this->assertSame(2, $summary['call_allowance_resolved']);
        $this->assertSame(0, $summary['call_allowance_unresolved']);

        $staff = Staff::withoutGlobalScopes()->where('legacy_cno_psn', 'C006P006')->firstOrFail();
        $employment = StaffEmployment::query()->where('staff_id', $staff->id)->firstOrFail();

        $this->assertSame($nursingCadre->id, $employment->cadre_id);
        $this->assertSame($nursingRank->id, $employment->rank_id);
        $this->assertDatabaseHas('staff_allowance_assignments', [
            'staff_id' => $staff->id,
            'allowance_type_id' => \App\Domain\Staff\Models\AllowanceType::query()
                ->forMda($staff->mda_id)
                ->where('code', 'call_nurse_others')
                ->value('id'),
            'is_eligible' => true,
        ]);
    }

    public function test_include_retired_publish_publishes_retired_staff_and_status_history(): void
    {
        $summary = app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'include_retired' => true,
            'publish' => true,
        ]);

        $this->assertSame(5, $summary['rows_published']);
        $this->assertSame(1, $summary['retired_staff']);
        $this->assertSame(5, Staff::withoutGlobalScopes()->count());

        $retiredStaff = Staff::withoutGlobalScopes()->where('legacy_cno_psn', 'C002P002')->firstOrFail();

        $this->assertSame('retired', $retiredStaff->status);
        $this->assertSame('retired', StaffEmployment::query()->where('staff_id', $retiredStaff->id)->value('employment_status'));
        $retiredHistory = StaffStatusHistory::query()
            ->where('staff_id', $retiredStaff->id)
            ->where('status', 'retired')
            ->latest('effective_from')
            ->first();

        $this->assertNotNull($retiredHistory);
        $this->assertSame('2020-01-01', substr((string) $retiredHistory->effective_from, 0, 10));
    }

    public function test_station_aliases_are_resolved_without_new_missing_station_warnings(): void
    {
        $hmb = Mda::query()->where('code', 'HMB')->firstOrFail();

        foreach (['GH WUSHISHI', 'JBANM'] as $stationName) {
            \App\Domain\Organization\Models\Station::withoutGlobalScopes()->create([
                'mda_id' => $hmb->id,
                'code' => strtoupper(str_replace(' ', '_', $stationName)),
                'name' => $stationName,
                'status' => 'active',
            ]);
        }

        DB::connection('legacy')->table('staff_list')->insert([
            [
                'id' => 6,
                'cno' => 'C006',
                'name' => 'Alias Station One',
                'psn' => 'P006',
                'sex' => 'Male',
                'mda' => 'HOSPITAL MANAGEMENT BOARD',
                'station' => 'GH M.I WUSHISHI',
                'location' => 'MINNA',
                'salary_scale' => 'GL',
                'highest_qualification' => 'HND',
                'department' => 'ADMIN',
                'dob' => '1987-01-01',
                'dfa' => '2011-01-01',
                'dpa' => '2021-01-01',
                'edor' => '2047-01-01',
                'cadre' => 'ADMIN OFFICER',
                'rank' => 'A.O II',
                'level' => 8,
                'step' => 2,
                'basic_salary' => 42000,
                'gross' => 46000,
                'cno_psn' => 'C006P006',
                'status' => '1',
            ],
            [
                'id' => 7,
                'cno' => 'C007',
                'name' => 'Alias Station Two',
                'psn' => 'P007',
                'sex' => 'Female',
                'mda' => 'HOSPITAL MANAGEMENT BOARD',
                'station' => 'GH JBANM',
                'location' => 'MINNA',
                'salary_scale' => 'GL',
                'highest_qualification' => 'HND',
                'department' => 'ADMIN',
                'dob' => '1989-02-02',
                'dfa' => '2013-02-02',
                'dpa' => '2022-02-02',
                'edor' => '2049-02-02',
                'cadre' => 'ADMIN OFFICER',
                'rank' => 'A.O II',
                'level' => 8,
                'step' => 2,
                'basic_salary' => 43000,
                'gross' => 47000,
                'cno_psn' => 'C007P007',
                'status' => '1',
            ],
        ]);

        $summary = app(LegacyStaffImportService::class)->import([
            'limit' => 100,
        ]);

        $this->assertSame(6, $summary['rows_read']);
        $this->assertSame(1, $summary['missing_station']);
        $this->assertDatabaseHas('legacy_staff_import_rows', [
            'dedupe_key' => 'C006',
            'legacy_cno_psn' => 'C006P006',
        ]);
        $this->assertDatabaseHas('legacy_staff_import_rows', [
            'dedupe_key' => 'C007',
            'legacy_cno_psn' => 'C007P007',
        ]);
    }

    public function test_moh_station_alias_resolves_to_headquarters_station(): void
    {
        DB::connection('legacy')->table('staff_list')->insert([
            'id' => 6,
            'cno' => 'C006',
            'name' => 'Headquarters Alias User',
            'psn' => 'P006',
            'sex' => 'Male',
            'mda' => 'MINISTRY OF HEALTH',
            'station' => 'MOH',
            'location' => 'MINNA',
            'salary_scale' => 'GL',
            'highest_qualification' => 'HND',
            'department' => 'ADMIN',
            'dob' => '1986-06-06',
            'dfa' => '2012-06-06',
            'dpa' => '2021-06-06',
            'edor' => '2046-06-06',
            'cadre' => 'ADMIN OFFICER',
            'rank' => 'A.O II',
            'level' => 8,
            'step' => 2,
            'basic_salary' => 41000,
            'gross' => 45000,
            'cno_psn' => 'C006P006',
            'status' => '1',
        ]);

        $summary = app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'publish' => true,
        ]);

        $this->assertSame(5, $summary['rows_published']);
        $this->assertSame(1, $summary['missing_station']);

        $staff = Staff::withoutGlobalScopes()->where('legacy_cno_psn', 'C006P006')->firstOrFail();
        $employment = StaffEmployment::query()->where('staff_id', $staff->id)->firstOrFail();

        $this->assertSame('MOH HQTR', $employment->station?->name);
    }

    public function test_rank_fallback_aligns_cadre_when_unique_scale_level_match_exists(): void
    {
        $gl = \App\Domain\Staff\Models\SalaryScale::query()->where('code', 'GL')->firstOrFail();
        $mohAdminDepartmentId = \App\Domain\Organization\Models\Department::query()
            ->where('name', 'ADMIN')
            ->where('mda_id', Mda::query()->where('code', 'MOH')->value('id'))
            ->value('id');

        $nursingCadre = Cadre::query()->create([
            'salary_scale_id' => $gl->id,
            'department_id' => $mohAdminDepartmentId,
            'name' => 'NURSING OFFICER',
            'legacy_department_name' => 'ADMIN',
            'status' => 'active',
        ]);

        $fallbackRank = Rank::query()->create([
            'cadre_id' => $nursingCadre->id,
            'salary_scale_id' => $gl->id,
            'name' => 'SNO',
            'level' => 10,
            'status' => 'active',
        ]);

        DB::connection('legacy')->table('staff_list')->insert([
            'id' => 6,
            'cno' => 'C006',
            'name' => 'Fallback Rank User',
            'psn' => 'P006',
            'sex' => 'Female',
            'mda' => 'MINISTRY OF HEALTH',
            'station' => 'MOH HQTR',
            'location' => 'MINNA',
            'salary_scale' => 'GL',
            'highest_qualification' => 'HND',
            'department' => 'ADMIN',
            'dob' => '1984-06-06',
            'dfa' => '2010-06-06',
            'dpa' => '2021-06-06',
            'edor' => '2044-06-06',
            'cadre' => 'ADMIN OFFICER',
            'rank' => 'SNO',
            'level' => 10,
            'step' => 2,
            'basic_salary' => 51000,
            'gross' => 55000,
            'cno_psn' => 'C006P006',
            'status' => '1',
        ]);

        $summary = app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'publish' => true,
        ]);

        $this->assertSame(5, $summary['rows_published']);
        $this->assertSame(0, $summary['missing_rank']);
        $this->assertSame(1, $summary['cadre_auto_created']);
        $this->assertSame(1, $summary['rank_auto_created']);

        $staff = Staff::withoutGlobalScopes()->where('legacy_cno_psn', 'C006P006')->firstOrFail();
        $employment = StaffEmployment::query()->where('staff_id', $staff->id)->firstOrFail();

        $this->assertSame($fallbackRank->id, $employment->rank_id);
        $this->assertSame($nursingCadre->id, $employment->cadre_id);
    }

    public function test_staff_identity_matching_prevents_duplicate_records(): void
    {
        $mda = Mda::query()->where('code', 'MOH')->firstOrFail();

        Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'C001P001',
            'legacy_cno' => 'C001',
            'legacy_psn' => 'P001',
            'legacy_cno_psn' => 'C001P001',
            'surname' => 'Old',
            'first_name' => 'Name',
            'full_name' => 'Old Name',
            'status' => 'active',
        ]);

        app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'publish' => true,
        ]);

        $this->assertSame(4, Staff::withoutGlobalScopes()->count());
        $this->assertSame('Doe John Alpha', Staff::withoutGlobalScopes()->where('legacy_cno_psn', 'C001P001')->firstOrFail()->full_name);
        $this->assertNotNull(LegacyStaffImportRow::query()->where('legacy_cno_psn', 'C001P001')->first()?->matched_staff_id);
    }

    public function test_same_staff_number_with_different_name_creates_a_new_staff_record(): void
    {
        $mda = Mda::query()->where('code', 'MOH')->firstOrFail();

        Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'C001',
            'legacy_cno' => null,
            'legacy_psn' => null,
            'legacy_cno_psn' => null,
            'surname' => 'Existing',
            'first_name' => 'Officer',
            'full_name' => 'Existing Officer',
            'status' => 'active',
        ]);

        app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'publish' => true,
        ]);

        $this->assertSame(5, Staff::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('staff', [
            'mda_id' => $mda->id,
            'staff_number' => 'C001',
            'full_name' => 'Existing Officer',
        ]);
        $this->assertDatabaseHas('staff', [
            'mda_id' => $mda->id,
            'staff_number' => 'C001P001',
            'legacy_cno_psn' => 'C001P001',
            'full_name' => 'Doe John Alpha',
        ]);
    }

    public function test_mda_scoping_still_works_after_staff_import(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        app(LegacyStaffImportService::class)->import([
            'limit' => 100,
            'publish' => true,
        ]);

        $moh = Mda::query()->where('code', 'MOH')->firstOrFail();
        $hmb = Mda::query()->where('code', 'HMB')->firstOrFail();
        $mohUser = User::factory()->mdaUser($moh)->create();
        $mohUser->assignRole('MDA Admin');
        $hmbUser = User::factory()->mdaUser($hmb)->create();
        $hmbUser->assignRole('MDA Admin');

        $this->actingAs($mohUser);
        $this->assertSame(3, Staff::query()->count());
        $this->assertSame(['MOH'], Staff::query()->get()->pluck('mda.code')->unique()->values()->all());

        $this->actingAs($hmbUser);
        $this->assertSame(1, Staff::query()->count());
        $this->assertSame(['HMB'], Staff::query()->get()->pluck('mda.code')->unique()->values()->all());
    }
}
