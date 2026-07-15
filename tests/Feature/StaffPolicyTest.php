<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Models\User;
use App\Models\UserAccessScope;
use App\Policies\StaffPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_policy_enforces_mda_scope_and_global_access(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mdaA = Mda::query()->create(['code' => 'MOH', 'name' => 'MINISTRY OF HEALTH', 'status' => 'active']);
        $mdaB = Mda::query()->create(['code' => 'HMB', 'name' => 'HOSPITAL MANAGEMENT BOARD', 'status' => 'active']);

        $staffB = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mdaB->id,
            'staff_number' => 'STF-10',
            'surname' => 'Beta',
            'first_name' => 'User',
            'full_name' => 'Beta User',
            'status' => 'active',
        ]);

        $mdaUser = User::factory()->mdaUser($mdaA)->create();
        $mdaUser->assignRole('MDA Admin');

        $globalUser = User::factory()->superAdmin()->create();
        $globalUser->assignRole('Super Admin');

        $policy = app(StaffPolicy::class);

        $this->assertTrue($policy->viewAny($mdaUser));
        $this->assertFalse($policy->view($mdaUser, $staffB));
        $this->assertFalse($policy->update($mdaUser, $staffB));
        $this->assertFalse($policy->delete($mdaUser, $staffB));
        $this->assertTrue($policy->view($globalUser, $staffB));
        $this->assertTrue($policy->update($globalUser, $staffB));
        $this->assertTrue($policy->delete($globalUser, $staffB));
    }

    public function test_staff_policy_allows_explicit_multi_mda_access_only_for_assigned_mdas(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mdaA = Mda::query()->create(['code' => 'MOH', 'name' => 'MINISTRY OF HEALTH', 'status' => 'active']);
        $mdaB = Mda::query()->create(['code' => 'HMB', 'name' => 'HOSPITAL MANAGEMENT BOARD', 'status' => 'active']);
        $mdaC = Mda::query()->create(['code' => 'EDU', 'name' => 'MINISTRY OF EDUCATION', 'status' => 'active']);

        $staffB = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mdaB->id,
            'staff_number' => 'STF-20',
            'surname' => 'Beta',
            'first_name' => 'Scoped',
            'full_name' => 'Beta Scoped',
            'status' => 'active',
        ]);
        $staffC = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mdaC->id,
            'staff_number' => 'STF-30',
            'surname' => 'Gamma',
            'first_name' => 'Blocked',
            'full_name' => 'Gamma Blocked',
            'status' => 'active',
        ]);

        $user = User::factory()->mdaUser($mdaA)->create();
        $user->assignRole('MDA Admin');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'mda',
            'mda_id' => $mdaB->id,
        ]);

        $policy = app(StaffPolicy::class);

        $this->assertTrue($policy->view($user, $staffB));
        $this->assertTrue($policy->update($user, $staffB));
        $this->assertTrue($policy->delete($user, $staffB));
        $this->assertFalse($policy->view($user, $staffC));
        $this->assertFalse($policy->update($user, $staffC));
        $this->assertFalse($policy->delete($user, $staffC));
    }

    public function test_staff_policy_enforces_department_scope_within_the_same_mda(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mda = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $departmentA = \App\Domain\Organization\Models\Department::query()->create([
            'mda_id' => $mda->id,
            'code' => 'ADMIN',
            'name' => 'Administration',
            'status' => 'active',
        ]);
        $departmentB = \App\Domain\Organization\Models\Department::query()->create([
            'mda_id' => $mda->id,
            'code' => 'FIN',
            'name' => 'Finance',
            'status' => 'active',
        ]);

        $staffA = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'STF-40',
            'surname' => 'Admin',
            'first_name' => 'Scoped',
            'full_name' => 'Admin Scoped',
            'status' => 'active',
        ]);
        $staffB = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'STF-41',
            'surname' => 'Finance',
            'first_name' => 'Blocked',
            'full_name' => 'Finance Blocked',
            'status' => 'active',
        ]);

        StaffEmployment::query()->create([
            'staff_id' => $staffA->id,
            'mda_id' => $mda->id,
            'department_id' => $departmentA->id,
            'employment_status' => 'active',
            'is_current' => true,
        ]);
        StaffEmployment::query()->create([
            'staff_id' => $staffB->id,
            'mda_id' => $mda->id,
            'department_id' => $departmentB->id,
            'employment_status' => 'active',
            'is_current' => true,
        ]);

        $user = User::factory()->mdaUser($mda)->create();
        $user->assignRole('MDA Admin');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'department',
            'mda_id' => $mda->id,
            'department_id' => $departmentA->id,
        ]);

        $policy = app(StaffPolicy::class);

        $this->assertTrue($policy->view($user, $staffA));
        $this->assertTrue($policy->update($user, $staffA));
        $this->assertFalse($policy->view($user, $staffB));
        $this->assertFalse($policy->update($user, $staffB));
    }
}
