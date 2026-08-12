<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\MdaSetting;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\PromotionPolicy;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;
    protected Mda $otherMda;
    protected Department $department;
    protected Department $otherDepartment;
    protected Station $station;
    protected SalaryScale $scale;
    protected User $mdaAdmin;
    protected User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->mda = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $this->otherMda = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        MdaSetting::query()->create(['mda_id' => $this->mda->id, 'acronym' => 'MOH']);
        MdaSetting::query()->create(['mda_id' => $this->otherMda->id, 'acronym' => 'HMB']);

        $this->department = Department::query()->create(['mda_id' => $this->mda->id, 'code' => 'CLIN', 'name' => 'Clinical Services', 'status' => 'active']);
        $this->otherDepartment = Department::query()->create(['mda_id' => $this->otherMda->id, 'code' => 'ADMIN', 'name' => 'Administration', 'status' => 'active']);
        $this->station = Station::query()->create(['mda_id' => $this->mda->id, 'code' => 'HQ', 'name' => 'Headquarters', 'status' => 'active']);
        Station::query()->create(['mda_id' => $this->otherMda->id, 'code' => 'OTH', 'name' => 'Other Station', 'status' => 'active']);

        $this->scale = SalaryScale::query()->create([
            'mda_id' => $this->mda->id,
            'code' => 'GL',
            'name' => 'Grade Level',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);
        SalaryScale::query()->create([
            'mda_id' => $this->otherMda->id,
            'code' => 'GL',
            'name' => 'Grade Level',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        $ownCadre = Cadre::query()->create([
            'department_id' => $this->department->id,
            'salary_scale_id' => $this->scale->id,
            'name' => 'Medical Officer',
            'status' => 'active',
        ]);
        Rank::query()->create([
            'cadre_id' => $ownCadre->id,
            'salary_scale_id' => $this->scale->id,
            'name' => 'Senior Medical Officer',
            'level' => 9,
            'status' => 'active',
        ]);

        $otherCadre = Cadre::query()->create([
            'department_id' => $this->otherDepartment->id,
            'salary_scale_id' => $this->scale->id,
            'name' => 'Admin Officer',
            'status' => 'active',
        ]);
        Rank::query()->create([
            'cadre_id' => $otherCadre->id,
            'salary_scale_id' => $this->scale->id,
            'name' => 'Principal Admin Officer',
            'level' => 10,
            'status' => 'active',
        ]);

        $this->mdaAdmin = User::factory()->mdaUser($this->mda)->create();
        $this->mdaAdmin->assignRole('MDA Admin');

        $this->platformAdmin = User::factory()->create();
        $this->platformAdmin->assignRole('Platform Admin');
        UserAccessScope::query()->create([
            'user_id' => $this->platformAdmin->id,
            'scope_type' => 'platform',
            'state_code' => 'NG-NI',
            'mda_id' => null,
        ]);
    }

    public function test_mda_admin_can_manage_own_mda_departments_and_stations(): void
    {
        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/departments', [
                'code' => 'LAB',
                'name' => 'Laboratory Services',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.mda_id', $this->mda->id);

        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/stations', [
                'code' => 'ANNEX',
                'name' => 'Annex Office',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.mda_id', $this->mda->id);
    }

    public function test_mda_admin_cannot_manage_another_mdas_setup_records(): void
    {
        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/departments', [
                'mda_id' => $this->otherMda->id,
                'code' => 'FIN',
                'name' => 'Finance',
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/cadres', [
                'department_id' => $this->otherDepartment->id,
                'salary_scale_id' => $this->scale->id,
                'name' => 'Blocked Cadre',
                'status' => 'active',
            ])
            ->assertForbidden();

        $response = $this->actingAs($this->mdaAdmin)
            ->getJson('/api/setup-management')
            ->assertOk();

        $this->assertFalse(collect($response->json('data.departments'))->contains('id', $this->otherDepartment->id));
    }

    public function test_setup_dropdowns_are_mda_safe(): void
    {
        $otherRank = Rank::query()->where('name', 'Principal Admin Officer')->firstOrFail();
        $otherStation = Station::query()->where('mda_id', $this->otherMda->id)->firstOrFail();

        $staffOptionsResponse = $this->actingAs($this->mdaAdmin)
            ->getJson('/api/staff/options')
            ->assertOk();

        $this->assertFalse(collect($staffOptionsResponse->json('data.departments'))->contains('id', $this->otherDepartment->id));
        $this->assertFalse(collect($staffOptionsResponse->json('data.stations'))->contains('id', $otherStation->id));
        $this->assertFalse(collect($staffOptionsResponse->json('data.ranks'))->contains('id', $otherRank->id));

        $this->actingAs($this->mdaAdmin)
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonMissing(['id' => $otherRank->id, 'name' => $otherRank->name]);
    }

    public function test_rank_duplicate_is_blocked_with_validation_error_instead_of_server_error(): void
    {
        $cadre = Cadre::query()->where('department_id', $this->department->id)->where('name', 'Medical Officer')->firstOrFail();

        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/ranks', [
                'cadre_id' => $cadre->id,
                'salary_scale_id' => $this->scale->id,
                'name' => 'Senior Medical Officer',
                'level' => 9,
                'status' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_mda_admin_can_manage_own_mda_reference_setup_and_salary_structure_only(): void
    {
        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/allowance-types', [
                'code' => 'HAZ',
                'name' => 'Hazard Allowance',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.mda_id', $this->mda->id);

        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/qualification-types', [
                'code' => 'MBBS',
                'name' => 'Bachelor of Medicine',
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/promotion-policies', [
                'salary_scale_code' => 'GL',
                'min_level' => 7,
                'max_level' => 14,
                'required_years' => 3,
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/salary-structure-rates', [
                'salary_scale_id' => $this->scale->id,
                'level' => 9,
                'step' => 1,
                'basic_salary' => 100000,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.mda_id', $this->mda->id);
    }

    public function test_mda_admin_cannot_manage_another_mdas_reference_setup_or_salary_structure(): void
    {
        $otherScale = SalaryScale::query()->where('mda_id', $this->otherMda->id)->firstOrFail();
        $otherAllowance = AllowanceType::query()->create([
            'mda_id' => $this->otherMda->id,
            'code' => 'HAZ',
            'name' => 'Hazard Allowance',
            'status' => 'active',
        ]);
        $otherRate = SalaryStructureRate::query()->create([
            'mda_id' => $this->otherMda->id,
            'salary_scale_id' => $otherScale->id,
            'level' => 9,
            'step' => 1,
            'basic_salary' => 120000,
            'status' => 'active',
        ]);

        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/allowance-types', [
                'mda_id' => $this->otherMda->id,
                'code' => 'RUR',
                'name' => 'Rural Allowance',
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/salary-structure-rates', [
                'salary_scale_id' => $otherScale->id,
                'level' => 10,
                'step' => 1,
                'basic_salary' => 130000,
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->actingAs($this->mdaAdmin)
            ->postJson('/api/setup-management/salary-structure-rate-allowances', [
                'salary_structure_rate_id' => $otherRate->id,
                'allowance_type_id' => $otherAllowance->id,
                'amount' => 15000,
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_platform_admin_can_manage_reference_setup_and_other_mda_departments(): void
    {
        $this->actingAs($this->platformAdmin)
            ->postJson('/api/setup-management/allowance-types', [
                'mda_id' => $this->otherMda->id,
                'code' => 'HAZ',
                'name' => 'Hazard Allowance',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.mda_id', $this->otherMda->id);

        $this->actingAs($this->platformAdmin)
            ->postJson('/api/setup-management/qualification-types', [
                'code' => 'TEST_CERT',
                'name' => 'Test Certificate',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'TEST_CERT');

        $this->actingAs($this->platformAdmin)
            ->postJson('/api/setup-management/promotion-policies', [
                'salary_scale_code' => 'GL',
                'min_level' => 7,
                'max_level' => 14,
                'required_years' => 3,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.salary_scale_code', 'GL')
            ->assertJsonPath('data.required_years', 3);

        $this->assertSame(1, PromotionPolicy::query()->count());

        $this->actingAs($this->platformAdmin)
            ->postJson('/api/setup-management/departments', [
                'mda_id' => $this->otherMda->id,
                'code' => 'ICT',
                'name' => 'ICT',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.mda_id', $this->otherMda->id);

        $this->actingAs($this->platformAdmin)
            ->postJson('/api/setup-management/salary-structure-rates', [
                'mda_id' => $this->otherMda->id,
                'salary_scale_id' => $this->scale->id,
                'level' => 9,
                'step' => 1,
                'basic_salary' => 100000,
                'legacy_gross_salary' => 120000,
                'status' => 'active',
            ])
            ->assertUnprocessable();

        $otherScale = SalaryScale::query()->where('mda_id', $this->otherMda->id)->firstOrFail();

        $this->actingAs($this->platformAdmin)
            ->postJson('/api/setup-management/salary-structure-rates', [
                'mda_id' => $this->otherMda->id,
                'salary_scale_id' => $otherScale->id,
                'level' => 9,
                'step' => 1,
                'basic_salary' => 100000,
                'legacy_gross_salary' => 120000,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.mda_id', $this->otherMda->id);
    }
}
