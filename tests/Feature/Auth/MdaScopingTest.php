<?php

namespace Tests\Feature\Auth;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\QualificationScaleCeiling;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\SalaryScale;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MdaScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_roles_and_permissions_are_seeded(): void
    {
        $this->assertDatabaseHas('roles', ['name' => 'Super Admin']);
        $this->assertDatabaseHas('permissions', ['name' => 'view-mdas']);
        $this->assertDatabaseHas('permissions', ['name' => 'manage-users']);
    }

    public function test_super_admin_can_access_all_mdas(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $superAdmin->assignRole('Super Admin');

        $mdaA = Mda::factory()->create();
        $mdaB = Mda::factory()->create();

        $response = $this
            ->actingAs($superAdmin)
            ->getJson('/api/mdas');

        $response
            ->assertOk()
            ->assertJsonFragment(['id' => $mdaA->id, 'name' => $mdaA->name])
            ->assertJsonFragment(['id' => $mdaB->id, 'name' => $mdaB->name]);
    }

    public function test_mda_user_only_sees_departments_for_assigned_mda(): void
    {
        $mdaA = Mda::factory()->create();
        $mdaB = Mda::factory()->create();

        $departmentA = Department::factory()->create(['mda_id' => $mdaA->id]);
        $departmentB = Department::factory()->create(['mda_id' => $mdaB->id]);

        $user = User::factory()->mdaUser($mdaA)->create();
        $user->assignRole('MDA Admin');

        $response = $this
            ->actingAs($user)
            ->getJson('/api/departments?mda_id='.$mdaB->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
        $response->assertJsonMissing(['id' => $departmentB->id, 'name' => $departmentB->name]);
    }

    public function test_mda_user_cannot_see_another_mda_stations(): void
    {
        $mdaA = Mda::factory()->create();
        $mdaB = Mda::factory()->create();

        $stationA = Station::factory()->create(['mda_id' => $mdaA->id]);
        $stationB = Station::factory()->create(['mda_id' => $mdaB->id]);

        $user = User::factory()->mdaUser($mdaA)->create();
        $user->assignRole('MDA Admin');

        $response = $this
            ->actingAs($user)
            ->getJson('/api/stations?mda_id='.$mdaB->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
        $response->assertJsonMissing(['id' => $stationB->id, 'name' => $stationB->name]);
    }

    public function test_multi_mda_user_only_sees_assigned_mdas_departments_and_stations(): void
    {
        $mdaA = Mda::factory()->create(['code' => 'MOH']);
        $mdaB = Mda::factory()->create(['code' => 'HMB']);
        $mdaC = Mda::factory()->create(['code' => 'EDU']);

        $departmentA = Department::factory()->create(['mda_id' => $mdaA->id]);
        $departmentB = Department::factory()->create(['mda_id' => $mdaB->id]);
        $departmentC = Department::factory()->create(['mda_id' => $mdaC->id]);
        $stationA = Station::factory()->create(['mda_id' => $mdaA->id]);
        $stationB = Station::factory()->create(['mda_id' => $mdaB->id]);
        $stationC = Station::factory()->create(['mda_id' => $mdaC->id]);

        $user = User::factory()->mdaUser($mdaA)->create();
        $user->assignRole('MDA Admin');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'mda',
            'mda_id' => $mdaB->id,
        ]);

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonFragment(['id' => $mdaA->id, 'code' => $mdaA->code])
            ->assertJsonFragment(['id' => $mdaB->id, 'code' => $mdaB->code])
            ->assertJsonMissing(['id' => $mdaC->id, 'code' => $mdaC->code]);

        $this->actingAs($user)
            ->getJson('/api/departments')
            ->assertOk()
            ->assertJsonFragment(['id' => $departmentA->id, 'name' => $departmentA->name])
            ->assertJsonFragment(['id' => $departmentB->id, 'name' => $departmentB->name])
            ->assertJsonMissing(['id' => $departmentC->id, 'name' => $departmentC->name]);

        $this->actingAs($user)
            ->getJson('/api/stations')
            ->assertOk()
            ->assertJsonFragment(['id' => $stationA->id, 'name' => $stationA->name])
            ->assertJsonFragment(['id' => $stationB->id, 'name' => $stationB->name])
            ->assertJsonMissing(['id' => $stationC->id, 'name' => $stationC->name]);
    }

    public function test_state_user_can_create_mda_with_default_salary_scales_and_qualification_ceilings(): void
    {
        $stateUser = User::factory()->create();
        $stateUser->assignRole('Platform Admin');
        UserAccessScope::query()->create([
            'user_id' => $stateUser->id,
            'scope_type' => 'state',
            'state_code' => 'NG-NI',
            'mda_id' => null,
        ]);

        $response = $this->actingAs($stateUser)
            ->postJson('/api/mdas', [
                'code' => 'EDU',
                'name' => 'Ministry of Education',
                'status' => 'active',
            ])
            ->assertCreated();

        $mda = Mda::query()->findOrFail($response->json('data.id'));

        $this->assertSame(4, SalaryScale::query()->forMda($mda->id)->count());
        $this->assertSame(13, QualificationType::query()->unified()->count());

        $glScale = SalaryScale::query()->forMda($mda->id)->where('code', 'GL')->firstOrFail();
        $hnd = QualificationType::query()->where('code', 'HND')->firstOrFail();

        $this->assertDatabaseHas('qualification_scale_ceilings', [
            'qualification_type_id' => $hnd->id,
            'salary_scale_id' => $glScale->id,
            'max_level' => 15,
            'status' => 'active',
        ]);
        $this->assertGreaterThan(0, QualificationScaleCeiling::query()->where('salary_scale_id', $glScale->id)->count());
    }
}
