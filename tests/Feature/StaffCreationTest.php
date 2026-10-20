<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffSalaryPlacementService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StaffCreationTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;

    protected Mda $otherMda;

    protected Department $department;

    protected Station $station;

    protected Cadre $cadre;

    protected Rank $rank;

    protected SalaryScale $scale;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mda = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $this->otherMda = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $this->department = Department::query()->create(['mda_id' => $this->mda->id, 'code' => 'ADMIN', 'name' => 'Administration', 'status' => 'active']);
        $this->station = Station::query()->create(['mda_id' => $this->mda->id, 'code' => 'HQ', 'name' => 'Headquarters', 'status' => 'active']);
        $this->scale = SalaryScale::query()->firstOrCreate(['code' => 'GL'], ['name' => 'Grade Level', 'status' => 'active']);
        $this->cadre = Cadre::query()->create(['department_id' => $this->department->id, 'salary_scale_id' => $this->scale->id, 'name' => 'Administrative Officer', 'status' => 'active']);
        $this->rank = Rank::query()->create(['cadre_id' => $this->cadre->id, 'salary_scale_id' => $this->scale->id, 'name' => 'Officer I', 'level' => 9, 'status' => 'active']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->mdaUser($this->mda)->create();
        $this->user->givePermissionTo(['view-staff', 'create-staff']);
    }

    public function test_create_staff_permission_creates_identity_and_audit_history(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload())
            ->assertCreated()->assertJsonPath('data.full_name', 'Musa Amina')->assertJsonPath('data.mda.id', $this->mda->id);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('staff_status_histories', ['staff_id' => $id, 'status' => 'active']);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $id, 'auditable_type' => Staff::class, 'actor_user_id' => $this->user->id, 'event_code' => 'created']);
        $this->actingAs($this->user)->getJson(route('api.staff.index'))->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_view_or_update_permission_does_not_grant_create_permission(): void
    {
        $this->user->syncPermissions(['view-staff', 'update-staff']);
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('staff', 0);
    }

    public function test_staff_cannot_be_created_in_an_inaccessible_mda(): void
    {
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload(['mda_id' => $this->otherMda->id]))->assertForbidden();
        $this->assertDatabaseCount('staff', 0);
    }

    public function test_staff_number_is_unique_within_mda_but_can_be_reused_in_another_mda(): void
    {
        Staff::query()->create([...$this->payload(['mda_id' => $this->otherMda->id]), 'full_name' => 'Other Officer']);
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload())->assertCreated();
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('staff_number');
        $this->assertDatabaseCount('staff', 2);
    }

    public function test_foreign_mda_appointment_references_and_mismatched_cadres_are_rejected(): void
    {
        $otherDepartment = Department::query()->create(['mda_id' => $this->otherMda->id, 'code' => 'OTHER', 'name' => 'Other department', 'status' => 'active']);
        $otherStation = Station::query()->create(['mda_id' => $this->otherMda->id, 'code' => 'OTHER', 'name' => 'Other station', 'status' => 'active']);
        $otherCadre = Cadre::query()->create(['department_id' => $otherDepartment->id, 'name' => 'Other cadre', 'status' => 'active']);
        $otherRank = Rank::query()->create(['cadre_id' => $otherCadre->id, 'name' => 'Other rank', 'status' => 'active']);
        foreach ([
            ['mda_id' => $this->otherMda->id],
            ['department_id' => $otherDepartment->id],
            ['station_id' => $otherStation->id],
            ['department_id' => $this->department->id, 'cadre_id' => $otherCadre->id],
            ['department_id' => $this->department->id, 'cadre_id' => $this->cadre->id, 'rank_id' => $otherRank->id],
        ] as $employment) {
            $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload(['employment' => $employment]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('staff', 0);
    }

    public function test_department_scoped_creator_must_use_an_assigned_department(): void
    {
        $this->user->accessScopes()->create(['scope_type' => 'department', 'mda_id' => $this->mda->id, 'department_id' => $this->department->id]);
        $other = Department::query()->create(['mda_id' => $this->mda->id, 'code' => 'FIN', 'name' => 'Finance', 'status' => 'active']);
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('employment.department_id');
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload(['employment' => ['department_id' => $other->id]]))->assertUnprocessable()->assertJsonValidationErrors('employment.department_id');
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload(['employment' => ['department_id' => $this->department->id]]))->assertCreated();
        $this->assertDatabaseCount('staff', 1);
    }

    public function test_station_scoped_creator_cannot_use_another_station(): void
    {
        $this->user->forceFill(['station_id' => $this->station->id])->save();
        $other = Station::query()->create(['mda_id' => $this->mda->id, 'code' => 'OTHER', 'name' => 'Other station', 'status' => 'active']);
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('employment.station_id');
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload(['employment' => ['station_id' => $other->id]]))->assertUnprocessable()->assertJsonValidationErrors('employment.station_id');
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload(['employment' => ['station_id' => $this->station->id]]))->assertCreated();
    }

    public function test_invalid_data_and_incomplete_salary_placement_do_not_create_records(): void
    {
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload([
            'date_of_birth' => now()->addDay()->toDateString(),
            'personal_detail' => ['email' => 'not-an-email'],
            'salary_placement' => ['salary_scale_id' => $this->scale->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['date_of_birth', 'personal_detail.email', 'salary_placement.level', 'salary_placement.step']);
        $this->assertDatabaseCount('staff', 0);
    }

    public function test_optional_related_records_are_created_with_the_staff_record(): void
    {
        $qualification = QualificationType::query()->create(['code' => 'BSC', 'name' => 'B.Sc.', 'status' => 'active']);
        SalaryStructureRate::query()->create(['salary_scale_id' => $this->scale->id, 'level' => 9, 'step' => 2, 'grade_code' => 'GL09', 'basic_salary' => 50000, 'status' => 'active']);
        $id = $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload([
            'legacy_cno' => 'CNO1', 'legacy_psn' => 'PSN1', 'is_contract_staff' => true,
            'personal_detail' => ['email' => 'amina@example.com', 'phone' => '08030000001'],
            'employment' => ['department_id' => $this->department->id, 'station_id' => $this->station->id, 'cadre_id' => $this->cadre->id, 'rank_id' => $this->rank->id],
            'salary_placement' => ['salary_scale_id' => $this->scale->id, 'level' => 9, 'step' => 2],
            'qualification' => ['qualification_type_id' => $qualification->id, 'is_highest' => true],
        ]))->assertCreated()->assertJsonPath('data.is_contract_staff', true)->json('data.id');
        $this->assertDatabaseHas('staff', ['id' => $id, 'legacy_cno_psn' => 'CNO1PSN1']);
        $this->assertDatabaseHas('staff_personal_details', ['staff_id' => $id, 'email' => 'amina@example.com']);
        $this->assertDatabaseHas('staff_employments', ['staff_id' => $id, 'mda_id' => $this->mda->id, 'department_id' => $this->department->id, 'is_current' => true]);
        $this->assertDatabaseHas('staff_salary_placements', ['staff_id' => $id, 'salary_scale_id' => $this->scale->id, 'level' => 9, 'step' => 2, 'basic_salary' => 50000]);
        $this->assertDatabaseHas('staff_qualifications', ['staff_id' => $id, 'qualification_type_id' => $qualification->id, 'is_highest' => true]);
    }

    public function test_related_record_failure_rolls_back_staff_and_employment(): void
    {
        $this->mock(StaffSalaryPlacementService::class)->shouldReceive('createPlacement')->once()
            ->andThrow(ValidationException::withMessages(['salary_placement' => 'The placement could not be saved.']));
        $this->actingAs($this->user)->postJson(route('api.staff.store'), $this->payload([
            'personal_detail' => ['email' => 'amina@example.com'],
            'employment' => ['department_id' => $this->department->id],
            'salary_placement' => ['salary_scale_id' => $this->scale->id, 'level' => 9, 'step' => 2],
        ]))->assertUnprocessable();
        $this->assertDatabaseCount('staff', 0);
        $this->assertDatabaseCount('staff_personal_details', 0);
        $this->assertDatabaseCount('staff_employments', 0);
        $this->assertDatabaseMissing('audit_logs', ['auditable_type' => Staff::class]);
    }

    protected function payload(array $overrides = []): array
    {
        return [...['mda_id' => $this->mda->id, 'staff_number' => 'HMB-NEW-001', 'surname' => 'Musa', 'first_name' => 'Amina', 'status' => 'active'], ...$overrides];
    }
}
