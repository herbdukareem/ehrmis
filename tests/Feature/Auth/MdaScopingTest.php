<?php

namespace Tests\Feature\Auth;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Models\User;
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
        $this->assertCount(1, $response->json('data'));
        $response
            ->assertJsonFragment(['id' => $departmentA->id, 'name' => $departmentA->name])
            ->assertJsonMissing(['id' => $departmentB->id, 'name' => $departmentB->name]);
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
        $this->assertCount(1, $response->json('data'));
        $response
            ->assertJsonFragment(['id' => $stationA->id, 'name' => $stationA->name])
            ->assertJsonMissing(['id' => $stationB->id, 'name' => $stationB->name]);
    }
}
