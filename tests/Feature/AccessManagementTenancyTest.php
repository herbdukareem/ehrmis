<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessManagementTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_assign_multi_mda_access_and_the_user_only_sees_assigned_mdas(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mdaA = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $mdaB = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $mdaC = Mda::query()->create(['code' => 'EDU', 'name' => 'Ministry of Education', 'status' => 'active']);

        $departmentA = Department::query()->create(['mda_id' => $mdaA->id, 'code' => 'ADMIN', 'name' => 'MOH ADMIN', 'status' => 'active']);
        $departmentB = Department::query()->create(['mda_id' => $mdaB->id, 'code' => 'ADMIN', 'name' => 'HMB ADMIN', 'status' => 'active']);
        $departmentC = Department::query()->create(['mda_id' => $mdaC->id, 'code' => 'ADMIN', 'name' => 'EDU ADMIN', 'status' => 'active']);

        $platformAdmin = User::factory()->create();
        $platformAdmin->assignRole('Platform Admin');
        UserAccessScope::query()->create([
            'user_id' => $platformAdmin->id,
            'scope_type' => 'platform',
            'state_code' => 'NG-NI',
            'mda_id' => null,
        ]);

        $managedUser = User::factory()->mdaUser($mdaA)->create();
        $managedUser->assignRole('MDA Admin');
        $managedUser->accessScopes()->create([
            'scope_type' => 'mda',
            'state_code' => null,
            'mda_id' => $mdaA->id,
        ]);

        $mdaAdminRoleId = Role::query()->where('name', 'MDA Admin')->where('scope', Role::SCOPE_GLOBAL)->value('id');

        $this->actingAs($platformAdmin)
            ->putJson(route('api.access-management.users.update', $managedUser), [
                'role_ids' => [$mdaAdminRoleId],
                'scope_type' => 'mda',
                'mda_id' => $mdaA->id,
                'mda_ids' => [$mdaB->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_access_scopes', [
            'user_id' => $managedUser->id,
            'scope_type' => 'mda',
            'mda_id' => $mdaA->id,
        ]);
        $this->assertDatabaseHas('user_access_scopes', [
            'user_id' => $managedUser->id,
            'scope_type' => 'mda',
            'mda_id' => $mdaB->id,
        ]);

        $accessibleMdas = $this->actingAs($managedUser)
            ->getJson('/api/me')
            ->assertOk()
            ->json('data.accessible_mdas');

        $this->assertSame(
            [$mdaA->id, $mdaB->id],
            collect($accessibleMdas)->pluck('id')->sort()->values()->all()
        );
        $this->assertNotContains($mdaC->id, collect($accessibleMdas)->pluck('id')->all());

        $this->actingAs($managedUser)
            ->getJson('/api/departments')
            ->assertOk()
            ->assertJsonFragment(['id' => $departmentA->id, 'name' => $departmentA->name])
            ->assertJsonFragment(['id' => $departmentB->id, 'name' => $departmentB->name])
            ->assertJsonMissing(['id' => $departmentC->id, 'name' => $departmentC->name]);
    }

    public function test_platform_admin_can_assign_department_scope_and_the_user_only_sees_assigned_departments(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mdaA = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $departmentA = Department::query()->create(['mda_id' => $mdaA->id, 'code' => 'ADMIN', 'name' => 'MOH ADMIN', 'status' => 'active']);
        $departmentB = Department::query()->create(['mda_id' => $mdaA->id, 'code' => 'FIN', 'name' => 'MOH FINANCE', 'status' => 'active']);

        $platformAdmin = User::factory()->create();
        $platformAdmin->assignRole('Platform Admin');
        UserAccessScope::query()->create([
            'user_id' => $platformAdmin->id,
            'scope_type' => 'platform',
            'state_code' => 'NG-NI',
            'mda_id' => null,
        ]);

        $managedUser = User::factory()->mdaUser($mdaA)->create();
        $managedUser->assignRole('MDA Admin');
        $managedUser->accessScopes()->create([
            'scope_type' => 'mda',
            'state_code' => null,
            'mda_id' => $mdaA->id,
        ]);

        $mdaAdminRoleId = Role::query()->where('name', 'MDA Admin')->where('scope', Role::SCOPE_GLOBAL)->value('id');

        $this->actingAs($platformAdmin)
            ->putJson(route('api.access-management.users.update', $managedUser), [
                'role_ids' => [$mdaAdminRoleId],
                'scope_type' => 'department',
                'mda_id' => $mdaA->id,
                'department_ids' => [$departmentA->id],
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_access_scopes', [
            'user_id' => $managedUser->id,
            'scope_type' => 'department',
            'mda_id' => $mdaA->id,
            'department_id' => $departmentA->id,
        ]);
        $this->assertDatabaseMissing('user_access_scopes', [
            'user_id' => $managedUser->id,
            'scope_type' => 'department',
            'department_id' => $departmentB->id,
        ]);

        $context = $this->actingAs($managedUser)
            ->getJson('/api/me')
            ->assertOk()
            ->json('data');

        $this->assertSame([$mdaA->id], collect($context['accessible_mdas'])->pluck('id')->values()->all());
        $this->assertSame([$departmentA->id], collect($context['accessible_departments'])->pluck('id')->values()->all());

        $this->actingAs($managedUser)
            ->getJson('/api/departments')
            ->assertOk()
            ->assertJsonFragment(['id' => $departmentA->id, 'name' => $departmentA->name])
            ->assertJsonMissing(['id' => $departmentB->id, 'name' => $departmentB->name]);
    }
}
