<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mda_admin_cannot_change_access_scope(): void
    {
        [$mdaAdmin, $managedUser, $mdaA, $mdaB] = $this->setUpMdaAdminScenario();

        $response = $this->actingAs($mdaAdmin)
            ->putJson(route('api.access-management.users.update', $managedUser), [
                'role_ids' => [],
                'scope_type' => 'mda',
                'mda_id' => $mdaA->id,
                'mda_ids' => [$mdaB->id],
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('user_access_scopes', [
            'user_id' => $managedUser->id,
            'scope_type' => 'mda',
            'mda_id' => $mdaB->id,
        ]);
    }

    public function test_mda_admin_can_create_user_in_own_mda(): void
    {
        [$mdaAdmin, , $mdaA] = $this->setUpMdaAdminScenario();
        $role = $this->createMdaRole($mdaA, 'Registry Officer');
        $station = Station::query()->create([
            'mda_id' => $mdaA->id,
            'code' => 'REG-1',
            'name' => 'Registry Station',
            'status' => 'active',
        ]);

        $this->actingAs($mdaAdmin)
            ->postJson(route('api.access-management.users.store'), [
                'name' => 'Local Registry',
                'email' => 'registry@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'status' => 'active',
                'role_ids' => [$role->id],
                'station_id' => $station->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.mda.id', $mdaA->id)
            ->assertJsonPath('data.station.id', $station->id);

        $this->assertDatabaseHas('users', [
            'email' => 'registry@example.test',
            'mda_id' => $mdaA->id,
            'station_id' => $station->id,
            'status' => 'active',
        ]);
    }

    public function test_mda_admin_cannot_create_user_in_another_mda_or_as_platform_scope(): void
    {
        [$mdaAdmin, , , $mdaB] = $this->setUpMdaAdminScenario();

        $this->actingAs($mdaAdmin)
            ->postJson(route('api.access-management.users.store'), [
                'name' => 'Blocked Cross MDA',
                'email' => 'blocked-cross@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'status' => 'active',
                'mda_id' => $mdaB->id,
            ])
            ->assertForbidden();

        $this->actingAs($mdaAdmin)
            ->postJson(route('api.access-management.users.store'), [
                'name' => 'Blocked Platform User',
                'email' => 'blocked-platform@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'status' => 'active',
                'scope_type' => 'platform',
            ])
            ->assertForbidden();
    }

    public function test_mda_admin_cannot_assign_privileged_roles_when_creating_user(): void
    {
        [$mdaAdmin] = $this->setUpMdaAdminScenario();
        $platformRoleId = Role::query()->where('name', 'Platform Admin')->where('scope', Role::SCOPE_GLOBAL)->value('id');

        $this->actingAs($mdaAdmin)
            ->postJson(route('api.access-management.users.store'), [
                'name' => 'Blocked Platform Role',
                'email' => 'blocked-role@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'status' => 'active',
                'role_ids' => [$platformRoleId],
            ])
            ->assertUnprocessable();
    }

    public function test_mda_admin_cannot_assign_platform_state_or_multi_mda_access(): void
    {
        [$mdaAdmin, $managedUser, $mdaA, $mdaB] = $this->setUpMdaAdminScenario();

        $this->actingAs($mdaAdmin)
            ->putJson(route('api.access-management.users.update', $managedUser), [
                'role_ids' => [],
                'scope_type' => 'platform',
            ])
            ->assertForbidden();

        $this->actingAs($mdaAdmin)
            ->putJson(route('api.access-management.users.update', $managedUser), [
                'role_ids' => [],
                'scope_type' => 'state',
                'state_code' => 'NG-KD',
            ])
            ->assertForbidden();

        $this->actingAs($mdaAdmin)
            ->putJson(route('api.access-management.users.update', $managedUser), [
                'role_ids' => [],
                'scope_type' => 'mda',
                'mda_id' => $mdaA->id,
                'mda_ids' => [$mdaB->id],
            ])
            ->assertForbidden();
    }

    public function test_mda_admin_cannot_edit_global_roles(): void
    {
        [$mdaAdmin] = $this->setUpMdaAdminScenario();
        $globalRole = Role::query()->where('name', 'HR Officer')->where('scope', Role::SCOPE_GLOBAL)->firstOrFail();

        $this->actingAs($mdaAdmin)
            ->putJson(route('api.access-management.roles.update', $globalRole), [
                'name' => 'HR Officer',
                'scope' => 'global',
                'permissions' => ['view-staff'],
            ])
            ->assertForbidden();
    }

    public function test_mda_admin_can_create_mda_role_for_own_mda(): void
    {
        [$mdaAdmin, , $mdaA, $mdaB] = $this->setUpMdaAdminScenario();

        $this->actingAs($mdaAdmin)
            ->postJson(route('api.access-management.roles.store'), [
                'name' => 'Records Clerk',
                'scope' => 'mda',
                'mda_id' => $mdaB->id,
                'permissions' => ['view-staff', 'view-reports'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'mda')
            ->assertJsonPath('data.mda_id', $mdaA->id);

        $this->assertDatabaseHas('roles', [
            'name' => 'Records Clerk',
            'scope' => 'mda',
            'mda_id' => $mdaA->id,
        ]);
    }

    public function test_mda_role_names_can_be_reused_in_different_mdas(): void
    {
        [$mdaAdmin, , $mdaA, $mdaB] = $this->setUpMdaAdminScenario();
        $this->createMdaRole($mdaB, 'Directors');

        $this->actingAs($mdaAdmin)
            ->postJson(route('api.access-management.roles.store'), [
                'name' => 'Directors',
                'scope' => 'mda',
                'mda_id' => $mdaA->id,
                'permissions' => ['view-staff'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'mda')
            ->assertJsonPath('data.mda_id', $mdaA->id);

        $this->assertDatabaseHas('roles', [
            'name' => 'Directors',
            'scope' => 'mda',
            'mda_id' => $mdaA->id,
        ]);
        $this->assertDatabaseHas('roles', [
            'name' => 'Directors',
            'scope' => 'mda',
            'mda_id' => $mdaB->id,
        ]);
    }

    public function test_mda_admin_can_assign_own_mda_role_to_own_mda_user(): void
    {
        [$mdaAdmin, $managedUser, $mdaA] = $this->setUpMdaAdminScenario();
        $role = $this->createMdaRole($mdaA, 'Local Records Clerk');

        $this->actingAs($mdaAdmin)
            ->putJson(route('api.access-management.users.update', $managedUser), [
                'role_ids' => [$role->id],
            ])
            ->assertOk();

        $this->assertTrue($managedUser->fresh()->roles->contains('id', $role->id));
    }

    public function test_mda_admin_cannot_assign_role_to_another_mda_user(): void
    {
        [$mdaAdmin, , $mdaA, $mdaB] = $this->setUpMdaAdminScenario();
        $otherUser = User::factory()->mdaUser($mdaB)->create();
        $otherUser->accessScopes()->create(['scope_type' => 'mda', 'state_code' => null, 'mda_id' => $mdaB->id]);
        $role = $this->createMdaRole($mdaA, 'Local Records Clerk');

        $this->actingAs($mdaAdmin)
            ->putJson(route('api.access-management.users.update', $otherUser), [
                'role_ids' => [$role->id],
            ])
            ->assertForbidden();
    }

    public function test_mda_admin_cannot_assign_another_mdas_role(): void
    {
        [$mdaAdmin, $managedUser, , $mdaB] = $this->setUpMdaAdminScenario();
        $otherRole = $this->createMdaRole($mdaB, 'External Clerk');

        $this->actingAs($mdaAdmin)
            ->putJson(route('api.access-management.users.update', $managedUser), [
                'role_ids' => [$otherRole->id],
            ])
            ->assertUnprocessable();
    }

    public function test_platform_admin_can_manage_access_scopes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mdaA = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $mdaB = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);

        $platformAdmin = $this->makePlatformAdmin();
        $managedUser = User::factory()->mdaUser($mdaA)->create();
        $managedUser->assignRole('MDA Admin');
        $managedUser->accessScopes()->create(['scope_type' => 'mda', 'state_code' => null, 'mda_id' => $mdaA->id]);

        $mdaAdminRoleId = Role::query()->where('name', 'MDA Admin')->where('scope', Role::SCOPE_GLOBAL)->value('id');

        $this->actingAs($platformAdmin)
            ->putJson(route('api.access-management.users.update', $managedUser), [
                'role_ids' => [$mdaAdminRoleId],
                'scope_type' => 'state',
                'state_code' => 'NG-KD',
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_access_scopes', [
            'user_id' => $managedUser->id,
            'scope_type' => 'state',
            'state_code' => 'NG-KD',
            'mda_id' => null,
        ]);
        $this->assertDatabaseMissing('user_access_scopes', [
            'user_id' => $managedUser->id,
            'scope_type' => 'mda',
            'mda_id' => $mdaB->id,
        ]);
    }

    public function test_platform_admin_can_create_users_for_any_mda_and_platform_scope(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mdaA = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $platformAdmin = $this->makePlatformAdmin();
        $mdaAdminRoleId = Role::query()->where('name', 'MDA Admin')->where('scope', Role::SCOPE_GLOBAL)->value('id');
        $reportViewerRoleId = Role::query()->where('name', 'Report Viewer')->where('scope', Role::SCOPE_GLOBAL)->value('id');

        $this->actingAs($platformAdmin)
            ->postJson(route('api.access-management.users.store'), [
                'name' => 'Cross MDA User',
                'email' => 'cross-mda@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'status' => 'active',
                'scope_type' => 'mda',
                'mda_id' => $mdaA->id,
                'role_ids' => [$mdaAdminRoleId],
            ])
            ->assertCreated()
            ->assertJsonPath('data.mda.id', $mdaA->id);

        $this->actingAs($platformAdmin)
            ->postJson(route('api.access-management.users.store'), [
                'name' => 'Platform Scope User',
                'email' => 'platform-scope@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'status' => 'active',
                'scope_type' => 'platform',
                'role_ids' => [$reportViewerRoleId],
            ])
            ->assertCreated()
            ->assertJsonPath('data.mda', null);

        $platformUser = User::query()->where('email', 'platform-scope@example.test')->firstOrFail();
        $this->assertDatabaseHas('user_access_scopes', [
            'user_id' => $platformUser->id,
            'scope_type' => 'platform',
            'mda_id' => null,
        ]);
    }

    public function test_platform_admin_cannot_assign_station_outside_selected_primary_mda(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mdaA = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $mdaB = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $station = Station::query()->create([
            'mda_id' => $mdaB->id,
            'code' => 'HMB-1',
            'name' => 'Wrong MDA Station',
            'status' => 'active',
        ]);

        $platformAdmin = $this->makePlatformAdmin();
        $mdaAdminRoleId = Role::query()->where('name', 'MDA Admin')->where('scope', Role::SCOPE_GLOBAL)->value('id');

        $this->actingAs($platformAdmin)
            ->postJson(route('api.access-management.users.store'), [
                'name' => 'Scoped User',
                'email' => 'scoped-user@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'status' => 'active',
                'scope_type' => 'mda',
                'mda_id' => $mdaA->id,
                'station_id' => $station->id,
                'role_ids' => [$mdaAdminRoleId],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('station_id');
    }

    public function test_platform_admin_can_manage_global_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $platformAdmin = $this->makePlatformAdmin();
        $role = Role::query()->where('name', 'Report Viewer')->where('scope', Role::SCOPE_GLOBAL)->firstOrFail();

        $this->actingAs($platformAdmin)
            ->putJson(route('api.access-management.roles.update', $role), [
                'name' => 'Report Viewer',
                'scope' => 'global',
                'permissions' => ['view-reports'],
            ])
            ->assertOk()
            ->assertJsonPath('data.scope', 'global');
    }

    public function test_super_admin_and_mis_admin_can_manage_all_roles_and_users(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mdaA = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $mdaB = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);

        $managedUser = User::factory()->mdaUser($mdaA)->create();
        $managedUser->assignRole('MDA Admin');
        $managedUser->accessScopes()->create(['scope_type' => 'mda', 'state_code' => null, 'mda_id' => $mdaA->id]);

        $actors = [
            $this->makeSuperAdmin(),
            $this->makeMisAdmin(),
        ];

        foreach ($actors as $actor) {
            $roleName = 'Shared Analyst '.$actor->id;

            $roleResponse = $this->actingAs($actor)
                ->postJson(route('api.access-management.roles.store'), [
                    'name' => $roleName,
                    'scope' => 'mda',
                    'mda_id' => $mdaB->id,
                    'permissions' => ['view-reports', 'export-reports'],
                ])
                ->assertCreated();

            $roleId = $roleResponse->json('data.id');

            $this->actingAs($actor)
                ->putJson(route('api.access-management.users.update', $managedUser), [
                    'role_ids' => [$roleId],
                    'scope_type' => 'mda',
                    'mda_id' => $mdaB->id,
                ])
                ->assertOk();

            $this->assertDatabaseHas('model_has_roles', [
                'role_id' => $roleId,
                'model_id' => $managedUser->id,
                'model_type' => User::class,
            ]);
            $this->assertDatabaseHas('user_access_scopes', [
                'user_id' => $managedUser->id,
                'scope_type' => 'mda',
                'mda_id' => $mdaB->id,
            ]);
        }
    }

    /**
     * @return array{0: User, 1: User, 2: Mda, 3: Mda}
     */
    protected function setUpMdaAdminScenario(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mdaA = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $mdaB = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);

        $mdaAdmin = User::factory()->mdaUser($mdaA)->create();
        $mdaAdmin->assignRole('MDA Admin');
        $mdaAdmin->accessScopes()->create(['scope_type' => 'mda', 'state_code' => null, 'mda_id' => $mdaA->id]);

        $managedUser = User::factory()->mdaUser($mdaA)->create();
        $managedUser->accessScopes()->create(['scope_type' => 'mda', 'state_code' => null, 'mda_id' => $mdaA->id]);

        return [$mdaAdmin, $managedUser, $mdaA, $mdaB];
    }

    protected function createMdaRole(Mda $mda, string $name): Role
    {
        $role = Role::query()->create([
            'name' => $name,
            'guard_name' => 'web',
            'scope' => Role::SCOPE_MDA,
            'mda_id' => $mda->id,
        ]);
        $role->syncPermissions(['view-staff', 'view-reports']);

        return $role;
    }

    protected function makePlatformAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Platform Admin');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'platform',
            'state_code' => 'NG-NI',
            'mda_id' => null,
        ]);

        return $user;
    }

    protected function makeSuperAdmin(): User
    {
        $user = User::factory()->superAdmin()->create();
        $user->assignRole('Super Admin');

        return $user;
    }

    protected function makeMisAdmin(): User
    {
        $user = User::factory()->create([
            'user_type' => 'mis_admin',
            'mda_id' => null,
        ]);
        $user->assignRole('MIS Admin');

        return $user;
    }
}
