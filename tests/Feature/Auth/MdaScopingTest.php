<?php

namespace Tests\Feature\Auth;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
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
}
