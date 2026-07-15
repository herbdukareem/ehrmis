<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\SalaryStructureRateAllowance;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffPersonalDetail;
use App\Domain\Staff\Models\StaffQualification;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Domain\Staff\Models\StaffStatusHistory;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mdaA;
    protected Mda $mdaB;
    protected Staff $staffA;
    protected Staff $staffRetired;
    protected Staff $staffB;
    protected User $mdaUser;
    protected SalaryScale $salaryScale;
    protected AllowanceType $hazardType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->setUpStaffFixtures();
    }

    public function test_staff_index_loads(): void
    {
        $response = $this->actingAs($this->mdaUser)
            ->getJson('/api/staff');

        $response
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_staff_index_respects_mda_scoping(): void
    {
        $response = $this->actingAs($this->mdaUser)
            ->getJson('/api/staff');

        $rows = $response->json('data');

        $this->assertCount(2, $rows);
        $this->assertContains($this->staffA->id, array_column($rows, 'id'));
        $this->assertContains($this->staffRetired->id, array_column($rows, 'id'));
        $this->assertNotContains($this->staffB->id, array_column($rows, 'id'));
    }

    public function test_staff_search_and_filters_work(): void
    {
        $searchResponse = $this->actingAs($this->mdaUser)
            ->getJson('/api/staff?search=CNO-A1');

        $this->assertCount(1, $searchResponse->json('data'));
        $this->assertSame($this->staffA->id, $searchResponse->json('data.0.id'));

        $retiredResponse = $this->actingAs($this->mdaUser)
            ->getJson('/api/staff?retirement_state=retired');

        $this->assertCount(1, $retiredResponse->json('data'));
        $this->assertSame($this->staffRetired->id, $retiredResponse->json('data.0.id'));

        foreach ([
            'cno' => 'CNO-A1',
            'psn' => 'PSN-A1',
            'department_id' => $this->staffA->currentEmployment->department_id,
            'cadre_id' => $this->staffA->currentEmployment->cadre_id,
            'rank_id' => $this->staffA->currentEmployment->rank_id,
        ] as $filter => $value) {
            $response = $this->actingAs($this->mdaUser)->getJson("/api/staff?{$filter}={$value}");

            $response->assertOk();
            $this->assertContains($this->staffA->id, array_column($response->json('data'), 'id'));
            $this->assertNotContains($this->staffB->id, array_column($response->json('data'), 'id'));
        }
    }

    public function test_staff_filter_options_are_scoped_to_the_users_mda(): void
    {
        $response = $this->actingAs($this->mdaUser)
            ->getJson('/api/staff/options')
            ->assertOk();

        $this->assertSame([$this->mdaA->id], array_values(array_unique(array_column($response->json('data.departments'), 'mda_id'))));
        $this->assertNotContains($this->staffB->currentEmployment->department_id, array_column($response->json('data.departments'), 'id'));
    }

    public function test_staff_detail_loads_with_import_warning_summary(): void
    {
        $response = $this->actingAs($this->mdaUser)
            ->getJson('/api/staff/'.$this->staffA->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $this->staffA->id)
            ->assertJsonPath('data.current_employment.department_name', 'ADMIN')
            ->assertJsonPath('data.current_employment.station_name', 'MOH HQ')
            ->assertJsonPath('data.current_employment.cadre_name', 'ADMIN OFFICER')
            ->assertJsonPath('data.current_employment.rank_name', 'A.O I')
            ->assertJsonPath('data.current_salary_placement.salary_scale_code', 'GL')
            ->assertJsonPath('data.retirement_state', 'active')
            ->assertJsonPath('data.can_update_appointment', true)
            ->assertJsonPath('data.can_update_allowances', true)
            ->assertJsonPath('data.import_metadata.needs_call_allowance_clarification', true);
    }

    public function test_mda_user_cannot_view_or_update_other_mda_staff(): void
    {
        $payload = [
            'mda_id' => $this->mdaB->id,
            'staff_number' => $this->staffB->staff_number,
            'legacy_cno' => $this->staffB->legacy_cno,
            'legacy_psn' => $this->staffB->legacy_psn,
            'surname' => $this->staffB->surname,
            'first_name' => $this->staffB->first_name,
            'middle_name' => $this->staffB->middle_name,
            'full_name' => $this->staffB->full_name,
            'sex' => $this->staffB->sex,
            'date_of_birth' => optional($this->staffB->date_of_birth)->toDateString(),
            'status' => $this->staffB->status,
        ];

        $this->actingAs($this->mdaUser)
            ->getJson('/api/staff/'.$this->staffB->id)
            ->assertNotFound();

        $this->actingAs($this->mdaUser)
            ->putJson(route('api.staff.update', $this->staffB), $payload)
            ->assertNotFound();
    }

    public function test_past_expected_retirement_date_is_treated_as_retired_for_filters_and_detail_state(): void
    {
        $overdueStaff = $this->makeStaff(
            $this->mdaA,
            $this->staffA->currentEmployment->department_id,
            $this->staffA->currentEmployment->station_id,
            $this->staffA->currentEmployment->cadre_id,
            $this->staffA->currentEmployment->rank_id,
            'STF900',
            'CNO-OLD',
            'PSN-OLD',
            'Overdue Retirement',
            'active',
        );

        $overdueStaff->currentEmployment()->update([
            'expected_retirement_date' => '2011-07-01',
            'employment_status' => 'active',
        ]);

        $this->actingAs($this->mdaUser)
            ->getJson('/api/staff?retirement_state=retired')
            ->assertOk()
            ->assertJsonFragment(['id' => $overdueStaff->id]);

        $this->actingAs($this->mdaUser)
            ->getJson('/api/staff/'.$overdueStaff->id)
            ->assertOk()
            ->assertJsonPath('data.retirement_state', 'retired');
    }

    public function test_multi_mda_user_gets_filter_options_for_each_assigned_mda_only(): void
    {
        $user = User::factory()->mdaUser($this->mdaA)->create();
        $user->assignRole('MDA Admin');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'mda',
            'mda_id' => $this->mdaB->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/staff/options')
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->mdaA->id, $this->mdaB->id],
            array_values(array_unique(array_column($response->json('data.departments'), 'mda_id')))
        );
    }

    public function test_department_scoped_user_only_sees_staff_in_assigned_departments(): void
    {
        $otherDepartment = Department::query()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'FIN',
            'name' => 'FINANCE',
            'status' => 'active',
        ]);
        $otherStation = Station::withoutGlobalScopes()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'FIN_HQ',
            'name' => 'FINANCE HQ',
            'status' => 'active',
        ]);
        $otherStaff = $this->makeStaff(
            $this->mdaA,
            $otherDepartment->id,
            $otherStation->id,
            $this->staffA->currentEmployment->cadre_id,
            $this->staffA->currentEmployment->rank_id,
            'STF004',
            'CNO-A3',
            'PSN-A3',
            'Finance User',
            'active',
        );

        $user = User::factory()->mdaUser($this->mdaA)->create();
        $user->assignRole('MDA Admin');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'department',
            'mda_id' => $this->mdaA->id,
            'department_id' => $this->staffA->currentEmployment->department_id,
        ]);

        $indexResponse = $this->actingAs($user)
            ->getJson('/api/staff')
            ->assertOk();

        $this->assertContains($this->staffA->id, array_column($indexResponse->json('data'), 'id'));
        $this->assertNotContains($otherStaff->id, array_column($indexResponse->json('data'), 'id'));

        $this->actingAs($user)
            ->getJson('/api/staff/options')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->staffA->currentEmployment->department_id])
            ->assertJsonMissing(['id' => $otherDepartment->id, 'name' => $otherDepartment->name]);

        $this->actingAs($user)
            ->getJson('/api/staff/'.$otherStaff->id)
            ->assertForbidden();
    }

    public function test_department_scoped_user_cannot_move_staff_outside_assigned_departments(): void
    {
        $targetDepartment = Department::query()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'FIN',
            'name' => 'FINANCE',
            'status' => 'active',
        ]);
        $targetStation = Station::withoutGlobalScopes()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'FIN_HQ',
            'name' => 'FINANCE HQ',
            'status' => 'active',
        ]);
        $targetScale = SalaryScale::query()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'CONHESS',
            'name' => 'CONHESS',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);
        $targetCadre = Cadre::query()->create([
            'salary_scale_id' => $targetScale->id,
            'department_id' => $targetDepartment->id,
            'name' => 'ACCOUNT OFFICER',
            'legacy_department_name' => 'FINANCE',
            'status' => 'active',
        ]);
        $targetRank = Rank::query()->create([
            'cadre_id' => $targetCadre->id,
            'salary_scale_id' => $targetScale->id,
            'name' => 'AO II',
            'level' => 10,
            'status' => 'active',
        ]);
        SalaryStructureRate::query()->create([
            'mda_id' => $this->mdaA->id,
            'salary_scale_id' => $targetScale->id,
            'level' => 10,
            'step' => 3,
            'basic_salary' => 73000,
            'legacy_gross_salary' => 76000,
            'status' => 'active',
        ]);

        $user = User::factory()->mdaUser($this->mdaA)->create();
        $user->givePermissionTo('view-staff', 'update-staff-appointment');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'department',
            'mda_id' => $this->mdaA->id,
            'department_id' => $this->staffA->currentEmployment->department_id,
        ]);

        $this->actingAs($user)
            ->putJson("/api/staff/{$this->staffA->id}/appointment", [
                'department_id' => $targetDepartment->id,
                'station_id' => $targetStation->id,
                'location_name' => 'Central Accounts',
                'cadre_id' => $targetCadre->id,
                'rank_id' => $targetRank->id,
                'date_first_appointment' => '2010-01-01',
                'date_last_promotion' => '2024-05-01',
                'expected_retirement_date' => '2049-01-01',
                'employment_status' => 'active',
                'effective_from' => '2026-06-01',
                'salary_scale_id' => $targetScale->id,
                'level' => 10,
                'step' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_staff_passport_and_multi_page_documents_are_private_and_mda_scoped(): void
    {
        Storage::fake('local');

        $this->actingAs($this->mdaUser)
            ->post('/api/staff/'.$this->staffA->id.'/passport', [
                'passport' => UploadedFile::fake()->image('passport.jpg', 300, 400),
            ])
            ->assertOk();

        $response = $this->actingAs($this->mdaUser)
            ->post('/api/staff/'.$this->staffA->id.'/documents', [
                'title' => 'Appointment Letter',
                'document_type' => 'appointment',
                'compile_pdf' => true,
                'pages' => [
                    UploadedFile::fake()->image('page-1.jpg'),
                    UploadedFile::fake()->image('page-2.jpg'),
                ],
            ])
            ->assertOk();

        $detail = $this->actingAs($this->mdaUser)->getJson('/api/staff/'.$this->staffA->id)->assertOk();
        $detail
            ->assertJsonPath('data.documents.0.title', 'Appointment Letter')
            ->assertJsonPath('data.documents.0.compiled_pdf_url', '/api/staff/'.$this->staffA->id.'/documents/'.$response->json('data.id').'/compiled-pdf')
            ->assertJsonCount(2, 'data.documents.0.pages');

        $this->actingAs($this->mdaUser)
            ->get($detail->json('data.passport_url'))
            ->assertOk();

        $this->actingAs($this->mdaUser)
            ->get($detail->json('data.documents.0.pages.0.preview_url'))
            ->assertOk();

        $this->actingAs($this->mdaUser)
            ->get($detail->json('data.documents.0.compiled_pdf_url'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $otherMdaUser = User::factory()->mdaUser($this->mdaB)->create();
        $otherMdaUser->assignRole('MDA Admin');

        $this->actingAs($otherMdaUser)
            ->get($detail->json('data.documents.0.pages.0.preview_url'))
            ->assertNotFound();

        $this->assertDatabaseHas('staff_documents', ['id' => $response->json('data.id'), 'staff_id' => $this->staffA->id]);
        $this->assertDatabaseCount('staff_document_pages', 2);
    }

    public function test_single_pdf_generation_rejects_non_image_pages_before_storage(): void
    {
        Storage::fake('local');

        $this->actingAs($this->mdaUser)
            ->postJson('/api/staff/'.$this->staffA->id.'/documents', [
                'title' => 'Existing PDF',
                'compile_pdf' => true,
                'pages' => [UploadedFile::fake()->create('existing.pdf', 100, 'application/pdf')],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('compile_pdf');

        $this->assertDatabaseCount('staff_documents', 0);
    }

    public function test_staff_update_writes_audit_log(): void
    {
        $payload = [
            'mda_id' => $this->mdaA->id,
            'staff_number' => $this->staffA->staff_number,
            'legacy_cno' => $this->staffA->legacy_cno,
            'legacy_psn' => $this->staffA->legacy_psn,
            'surname' => 'Updated',
            'first_name' => $this->staffA->first_name,
            'middle_name' => $this->staffA->middle_name,
            'full_name' => 'Updated Alpha User',
            'sex' => 'male',
            'date_of_birth' => optional($this->staffA->date_of_birth)->toDateString(),
            'status' => 'active',
            'status_reason' => 'Correction',
            'status_effective_from' => '2026-01-01',
            'personal_detail' => [
                'lga' => 'Bosso',
                'state_of_origin' => 'Niger',
                'phone' => '08030000099',
                'email' => 'updated@example.com',
                'address' => 'Updated address',
                'marital_status' => 'Single',
                'file_no' => 'F-UPDATED',
            ],
        ];

        $this->actingAs($this->mdaUser)
            ->putJson(route('api.staff.update', $this->staffA), $payload)
            ->assertOk();

        $this->assertDatabaseHas('staff', [
            'id' => $this->staffA->id,
            'surname' => 'Updated',
            'full_name' => 'Updated Alpha User',
        ]);

        $this->assertTrue(
            AuditLog::query()
                ->where('auditable_type', Staff::class)
                ->where('auditable_id', $this->staffA->id)
                ->exists()
        );
    }

    public function test_authorized_user_can_update_allowances_and_recompute_current_gross_pay(): void
    {
        $ruralType = AllowanceType::query()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'rural',
            'name' => 'Rural Allowance',
            'status' => 'active',
        ]);
        $rate = SalaryStructureRate::query()->create([
            'mda_id' => $this->mdaA->id,
            'salary_scale_id' => $this->salaryScale->id,
            'level' => 9,
            'step' => 2,
            'basic_salary' => 50000,
            'legacy_gross_salary' => 56000,
            'status' => 'active',
        ]);
        SalaryStructureRateAllowance::query()->create([
            'mda_id' => $this->mdaA->id,
            'salary_structure_rate_id' => $rate->id,
            'allowance_type_id' => $this->hazardType->id,
            'amount' => 5000,
            'status' => 'active',
        ]);
        SalaryStructureRateAllowance::query()->create([
            'mda_id' => $this->mdaA->id,
            'salary_structure_rate_id' => $rate->id,
            'allowance_type_id' => $ruralType->id,
            'amount' => 2000,
            'status' => 'active',
        ]);

        $this->actingAs($this->mdaUser)
            ->putJson(route('api.staff.allowances.update', $this->staffA), [
                'assignments' => [
                    ['allowance_type_id' => $this->hazardType->id, 'is_eligible' => false],
                    ['allowance_type_id' => $ruralType->id, 'is_eligible' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.salary_summary.total_allowances', 2000)
            ->assertJsonPath('data.salary_summary.calculated_gross_salary', 52000);

        $this->assertDatabaseHas('staff_allowance_assignments', [
            'staff_id' => $this->staffA->id,
            'allowance_type_id' => $this->hazardType->id,
            'source' => 'staff_management',
            'is_eligible' => false,
        ]);
        $this->assertDatabaseHas('staff_allowance_assignments', [
            'staff_id' => $this->staffA->id,
            'allowance_type_id' => $ruralType->id,
            'source' => 'staff_management',
            'is_eligible' => true,
        ]);
        $this->assertDatabaseHas('staff_salary_placements', [
            'staff_id' => $this->staffA->id,
            'is_current' => true,
            'gross_salary' => 52000,
            'calculated_gross_salary_snapshot' => 52000,
        ]);

        $otherMdaUser = User::factory()->mdaUser($this->mdaB)->create();
        $otherMdaUser->assignRole('MDA Admin');

        $this->actingAs($otherMdaUser)
            ->putJson(route('api.staff.allowances.update', $this->staffA), [
                'assignments' => [
                    ['allowance_type_id' => $this->hazardType->id, 'is_eligible' => true],
                ],
            ])
            ->assertNotFound();
    }

    public function test_staff_allowance_updates_require_the_dedicated_permission(): void
    {
        $user = User::factory()->mdaUser($this->mdaA)->create();
        $user->givePermissionTo('view-staff', 'update-staff');

        $this->actingAs($user)
            ->getJson('/api/staff/'.$this->staffA->id)
            ->assertOk()
            ->assertJsonPath('data.can_update_allowances', false);

        $this->actingAs($user)
            ->putJson(route('api.staff.allowances.update', $this->staffA), [
                'assignments' => [
                    ['allowance_type_id' => $this->hazardType->id, 'is_eligible' => true],
                ],
            ])
            ->assertForbidden();
    }

    public function test_staff_appointment_updates_require_the_dedicated_permission(): void
    {
        $user = User::factory()->mdaUser($this->mdaA)->create();
        $user->givePermissionTo('view-staff', 'update-staff');

        $newCadre = Cadre::query()->create([
            'salary_scale_id' => $this->salaryScale->id,
            'department_id' => $this->staffA->currentEmployment->department_id,
            'name' => 'SENIOR ADMIN OFFICER',
            'status' => 'active',
        ]);
        $newRank = Rank::query()->create([
            'cadre_id' => $newCadre->id,
            'salary_scale_id' => $this->salaryScale->id,
            'name' => 'SAO',
            'level' => 12,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->getJson('/api/staff/'.$this->staffA->id)
            ->assertOk()
            ->assertJsonPath('data.can_update_appointment', false);

        $this->actingAs($user)
            ->putJson(route('api.staff.flagged-issues.resolve', $this->staffA), [
                'cadre_id' => $newCadre->id,
                'rank_id' => $newRank->id,
            ])
            ->assertForbidden();
    }

    public function test_authorized_user_can_update_current_appointment_and_salary_position(): void
    {
        $user = User::factory()->mdaUser($this->mdaA)->create();
        $user->givePermissionTo('view-staff', 'update-staff-appointment');

        $department = Department::query()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'FIN',
            'name' => 'FINANCE',
            'status' => 'active',
        ]);
        $station = Station::withoutGlobalScopes()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'FIN_HQ',
            'name' => 'FINANCE HQ',
            'status' => 'active',
        ]);
        $scale = SalaryScale::query()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'CONHESS',
            'name' => 'CONHESS',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);
        $cadre = Cadre::query()->create([
            'salary_scale_id' => $scale->id,
            'department_id' => $department->id,
            'name' => 'ACCOUNT OFFICER',
            'legacy_department_name' => 'FINANCE',
            'status' => 'active',
        ]);
        $rank = Rank::query()->create([
            'cadre_id' => $cadre->id,
            'salary_scale_id' => $scale->id,
            'name' => 'AO II',
            'level' => 10,
            'status' => 'active',
        ]);
        SalaryStructureRate::query()->create([
            'mda_id' => $this->mdaA->id,
            'salary_scale_id' => $scale->id,
            'level' => 10,
            'step' => 3,
            'basic_salary' => 73000,
            'legacy_gross_salary' => 76000,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->putJson("/api/staff/{$this->staffA->id}/appointment", [
                'department_id' => $department->id,
                'station_id' => $station->id,
                'location_name' => 'Central Accounts',
                'cadre_id' => $cadre->id,
                'rank_id' => $rank->id,
                'date_first_appointment' => '2010-01-01',
                'date_last_promotion' => '2024-05-01',
                'expected_retirement_date' => '2049-01-01',
                'employment_status' => 'active',
                'effective_from' => '2026-06-01',
                'salary_scale_id' => $scale->id,
                'level' => 10,
                'step' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.current_employment.department_name', 'FINANCE')
            ->assertJsonPath('data.current_employment.station_name', 'FINANCE HQ')
            ->assertJsonPath('data.current_employment.cadre_name', 'ACCOUNT OFFICER')
            ->assertJsonPath('data.current_employment.rank_name', 'AO II')
            ->assertJsonPath('data.current_salary_placement.salary_scale_code', 'CONHESS')
            ->assertJsonPath('data.current_salary_placement.level', 10)
            ->assertJsonPath('data.current_salary_placement.step', 3);

        $this->assertDatabaseHas('staff_employments', [
            'staff_id' => $this->staffA->id,
            'department_id' => $department->id,
            'station_id' => $station->id,
            'cadre_id' => $cadre->id,
            'rank_id' => $rank->id,
            'is_current' => true,
        ]);
        $this->assertDatabaseHas('staff_salary_placements', [
            'staff_id' => $this->staffA->id,
            'salary_scale_id' => $scale->id,
            'level' => 10,
            'step' => 3,
            'is_current' => true,
        ]);
    }

    public function test_flagged_issue_allowance_changes_require_allowance_permission(): void
    {
        $user = User::factory()->mdaUser($this->mdaA)->create();
        $user->givePermissionTo('view-staff', 'update-staff');

        $this->actingAs($user)
            ->putJson(route('api.staff.flagged-issues.resolve', $this->staffA), [
                'allowances' => [
                    ['allowance_type_id' => $this->hazardType->id, 'is_eligible' => true],
                ],
            ])
            ->assertForbidden();
    }

    public function test_flagged_issues_lists_staff_with_unresolved_warnings(): void
    {
        $this->actingAs($this->mdaUser)
            ->getJson('/api/staff/flagged-issues')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->staffA->id)
            ->assertJsonPath('data.0.issues.0.field', 'call_allowance')
            ->assertJsonCount(1, 'data');
    }

    public function test_resolving_flagged_issues_updates_staff_and_removes_it_from_the_list(): void
    {
        $newCadre = Cadre::query()->create([
            'salary_scale_id' => $this->salaryScale->id,
            'department_id' => $this->staffA->currentEmployment->department_id,
            'name' => 'NURSING OFFICER',
            'status' => 'active',
        ]);
        $newRank = Rank::query()->create([
            'cadre_id' => $newCadre->id,
            'salary_scale_id' => $this->salaryScale->id,
            'name' => 'SNO',
            'level' => 10,
            'status' => 'active',
        ]);
        $qualification = QualificationType::query()->where('code', 'HND')->firstOrFail();

        $response = $this->actingAs($this->mdaUser)
            ->putJson(route('api.staff.flagged-issues.resolve', $this->staffA), [
                'date_of_birth' => '1985-05-05',
                'cadre_id' => $newCadre->id,
                'rank_id' => $newRank->id,
                'qualification_type_id' => $qualification->id,
                'allowances' => [
                    ['allowance_type_id' => $this->hazardType->id, 'is_eligible' => true],
                ],
            ]);

        $response->assertOk()->assertJsonPath('data.date_of_birth', '1985-05-05');

        $this->assertDatabaseHas('staff_employments', [
            'staff_id' => $this->staffA->id,
            'cadre_id' => $newCadre->id,
            'rank_id' => $newRank->id,
            'is_current' => true,
        ]);
        $this->assertDatabaseHas('staff_qualifications', [
            'staff_id' => $this->staffA->id,
            'qualification_type_id' => $qualification->id,
            'is_highest' => true,
        ]);
        $this->assertDatabaseHas('staff_allowance_assignments', [
            'staff_id' => $this->staffA->id,
            'allowance_type_id' => $this->hazardType->id,
            'is_eligible' => true,
        ]);
        $this->assertDatabaseHas('legacy_staff_import_errors', [
            'field' => 'call_allowance',
            'resolved_by' => $this->mdaUser->id,
        ]);

        $this->actingAs($this->mdaUser)
            ->getJson('/api/staff/flagged-issues')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    protected function setUpStaffFixtures(): void
    {
        $this->mdaA = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $this->mdaB = Mda::query()->create([
            'code' => 'HMB',
            'name' => 'HOSPITAL MANAGEMENT BOARD',
            'status' => 'active',
        ]);

        $departmentA = Department::query()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'ADMIN',
            'name' => 'ADMIN',
            'status' => 'active',
        ]);

        $departmentB = Department::query()->create([
            'mda_id' => $this->mdaB->id,
            'code' => 'ADMIN',
            'name' => 'ADMIN',
            'status' => 'active',
        ]);

        $stationA = Station::withoutGlobalScopes()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'MOH_HQ',
            'name' => 'MOH HQ',
            'status' => 'active',
        ]);

        $stationB = Station::withoutGlobalScopes()->create([
            'mda_id' => $this->mdaB->id,
            'code' => 'HMB_HQ',
            'name' => 'HMB HQ',
            'status' => 'active',
        ]);

        $this->salaryScale = SalaryScale::query()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'GL',
            'name' => 'GRADE LEVEL',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        $cadreA = Cadre::query()->create([
            'salary_scale_id' => $this->salaryScale->id,
            'department_id' => $departmentA->id,
            'name' => 'ADMIN OFFICER',
            'legacy_department_name' => 'ADMIN',
            'status' => 'active',
        ]);

        $rankA = Rank::query()->create([
            'cadre_id' => $cadreA->id,
            'salary_scale_id' => $this->salaryScale->id,
            'name' => 'A.O I',
            'level' => 9,
            'status' => 'active',
        ]);

        QualificationType::query()->firstOrCreate(
            ['code' => 'HND'],
            [
                'name' => 'HND',
                'status' => 'active',
            ],
        );

        $this->hazardType = AllowanceType::query()->create([
            'mda_id' => $this->mdaA->id,
            'code' => 'hazard',
            'name' => 'Hazard Allowance',
            'status' => 'active',
        ]);

        $this->staffA = $this->makeStaff($this->mdaA, $departmentA->id, $stationA->id, $cadreA->id, $rankA->id, 'STF001', 'CNO-A1', 'PSN-A1', 'Alpha User', 'active');
        $this->staffRetired = $this->makeStaff($this->mdaA, $departmentA->id, $stationA->id, $cadreA->id, $rankA->id, 'STF002', 'CNO-A2', 'PSN-A2', 'Retired User', 'retired');
        $this->staffB = $this->makeStaff($this->mdaB, $departmentB->id, $stationB->id, $cadreA->id, $rankA->id, 'STF003', 'CNO-B1', 'PSN-B1', 'Beta User', 'active');

        $batch = LegacyStaffImportBatch::query()->create([
            'source_database' => 'ministry_of_health',
            'source_table' => 'staff_list',
            'status' => 'completed',
        ]);

        $importRow = LegacyStaffImportRow::query()->create([
            'batch_id' => $batch->id,
            'dedupe_key' => $this->staffA->legacy_cno_psn,
            'status' => 'published',
            'published_staff_id' => $this->staffA->id,
            'raw_payload' => ['call_allowance' => 'YES'],
            'normalized_payload' => ['allowances' => []],
        ]);

        LegacyStaffImportError::query()->create([
            'batch_id' => $batch->id,
            'row_id' => $importRow->id,
            'field' => 'call_allowance',
            'error_code' => 'call_allowance_unresolved',
            'message' => 'Legacy call allowance eligibility was detected, but no canonical call allowance type could be resolved.',
            'severity' => 'warning',
        ]);

        $this->mdaUser = User::factory()->mdaUser($this->mdaA)->create();
        $this->mdaUser->assignRole('MDA Admin');
    }

    protected function makeStaff(Mda $mda, int $departmentId, int $stationId, int $cadreId, int $rankId, string $staffNumber, string $legacyCno, string $legacyPsn, string $fullName, string $status): Staff
    {
        $staff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => $staffNumber,
            'legacy_cno' => $legacyCno,
            'legacy_psn' => $legacyPsn,
            'legacy_cno_psn' => $legacyCno.$legacyPsn,
            'surname' => explode(' ', $fullName)[0],
            'first_name' => explode(' ', $fullName)[1] ?? 'User',
            'full_name' => $fullName,
            'sex' => 'male',
            'date_of_birth' => '1988-01-01',
            'status' => $status,
        ]);

        StaffPersonalDetail::query()->create([
            'staff_id' => $staff->id,
            'lga' => 'Bosso',
            'state_of_origin' => 'Niger',
            'phone' => '08030000001',
            'email' => strtolower(str_replace(' ', '.', $fullName)).'@example.com',
        ]);

        StaffEmployment::query()->create([
            'staff_id' => $staff->id,
            'mda_id' => $mda->id,
            'department_id' => $departmentId,
            'station_id' => $stationId,
            'cadre_id' => $cadreId,
            'rank_id' => $rankId,
            'date_first_appointment' => '2010-01-01',
            'date_last_promotion' => '2020-01-01',
            'expected_retirement_date' => $status === 'retired' ? '2024-01-01' : '2048-01-01',
            'employment_status' => $status,
            'is_current' => true,
        ]);

        StaffSalaryPlacement::query()->create([
            'staff_id' => $staff->id,
            'salary_scale_id' => $this->salaryScale->id,
            'level' => 9,
            'step' => 2,
            'basic_salary' => 50000,
            'gross_salary' => 55000,
            'basic_salary_snapshot' => 50000,
            'calculated_gross_salary_snapshot' => 55000,
            'is_current' => true,
        ]);

        StaffQualification::query()->create([
            'staff_id' => $staff->id,
            'qualification_name' => 'HND',
            'highest_qualification_name' => 'HND',
            'is_highest' => true,
            'source' => 'legacy_import',
        ]);

        StaffAllowanceAssignment::query()->create([
            'staff_id' => $staff->id,
            'allowance_type_id' => $this->hazardType->id,
            'is_eligible' => true,
            'source' => 'legacy_import',
        ]);

        StaffStatusHistory::query()->create([
            'staff_id' => $staff->id,
            'status' => $status,
            'reason' => 'Seeded for tests',
            'effective_from' => '2024-01-01',
        ]);

        return $staff;
    }

}
