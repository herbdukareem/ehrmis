<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
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
        $this->assertTrue($policy->view($globalUser, $staffB));
        $this->assertTrue($policy->update($globalUser, $staffB));
    }
}
