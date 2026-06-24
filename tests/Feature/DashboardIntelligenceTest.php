<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Domain\Staff\Models\StaffStatusHistory;
use App\Models\User;
use App\Models\UserAccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_mda_scoped_workforce_intelligence(): void
    {
        CarbonImmutable::setTestNow('2026-06-13');
        $mda = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $otherMda = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $department = Department::query()->create(['mda_id' => $mda->id, 'code' => 'CLIN', 'name' => 'Clinical Services', 'status' => 'active']);
        $scale = SalaryScale::query()->create(['mda_id' => $mda->id, 'code' => 'GL', 'name' => 'Grade Level', 'min_level' => 1, 'max_level' => 17, 'min_step' => 1, 'max_step' => 15, 'status' => 'active']);
        $cadre = Cadre::query()->create(['department_id' => $department->id, 'salary_scale_id' => $scale->id, 'name' => 'Medical Officer', 'status' => 'active']);
        $hazard = AllowanceType::query()->create(['mda_id' => $mda->id, 'code' => 'hazard', 'name' => 'Hazard Allowance', 'status' => 'active']);

        $staff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id, 'staff_number' => 'MOH-001', 'surname' => 'One',
            'first_name' => 'Officer', 'full_name' => 'Officer One', 'sex' => 'female', 'status' => 'active',
        ]);
        StaffEmployment::query()->create([
            'staff_id' => $staff->id, 'mda_id' => $mda->id, 'department_id' => $department->id,
            'cadre_id' => $cadre->id, 'expected_retirement_date' => '2026-06-28',
            'employment_status' => 'active', 'is_current' => true,
        ]);
        StaffSalaryPlacement::query()->create(['staff_id' => $staff->id, 'salary_scale_id' => $scale->id, 'level' => 9, 'step' => 1, 'is_current' => true]);
        StaffAllowanceAssignment::query()->create(['staff_id' => $staff->id, 'allowance_type_id' => $hazard->id, 'is_eligible' => true, 'source' => 'test']);
        StaffStatusHistory::query()->create(['staff_id' => $staff->id, 'status' => 'retired', 'effective_from' => '2025-04-01']);

        Staff::withoutGlobalScopes()->create([
            'mda_id' => $otherMda->id, 'staff_number' => 'HMB-001', 'surname' => 'Hidden',
            'first_name' => 'Officer', 'full_name' => 'Hidden Officer', 'sex' => 'male', 'status' => 'active',
        ]);

        $user = User::factory()->mdaUser($mda)->create();
        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.counts.staff', 1)
            ->assertJsonPath('data.counts.active_staff', 1)
            ->assertJsonPath('data.retirement_windows.this_month', 1)
            ->assertJsonPath('data.distributions.departments.0.label', 'Clinical Services')
            ->assertJsonPath('data.distributions.salary_scales.0.label', 'GL')
            ->assertJsonPath('data.distributions.gender.0.label', 'Female')
            ->assertJsonPath('data.distributions.cadres.0.allowances.0.code', 'hazard');

        CarbonImmutable::setTestNow();
    }

    public function test_dashboard_aggregates_only_assigned_mdas_for_multi_mda_user(): void
    {
        CarbonImmutable::setTestNow('2026-06-13');

        $mdaA = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $mdaB = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $mdaC = Mda::query()->create(['code' => 'EDU', 'name' => 'Ministry of Education', 'status' => 'active']);
        foreach ([[$mdaA, 'MOH-001'], [$mdaB, 'HMB-001'], [$mdaC, 'EDU-001']] as [$mda, $staffNumber]) {
            $scale = SalaryScale::query()->create(['mda_id' => $mda->id, 'code' => 'GL', 'name' => 'Grade Level', 'min_level' => 1, 'max_level' => 17, 'min_step' => 1, 'max_step' => 15, 'status' => 'active']);
            $hazard = AllowanceType::query()->create(['mda_id' => $mda->id, 'code' => 'hazard', 'name' => 'Hazard Allowance', 'status' => 'active']);
            $department = Department::query()->create(['mda_id' => $mda->id, 'code' => $mda->code, 'name' => $mda->name.' Admin', 'status' => 'active']);
            $cadre = Cadre::query()->create(['department_id' => $department->id, 'salary_scale_id' => $scale->id, 'name' => $mda->code.' Officer', 'status' => 'active']);
            $staff = Staff::withoutGlobalScopes()->create([
                'mda_id' => $mda->id,
                'staff_number' => $staffNumber,
                'surname' => $mda->code,
                'first_name' => 'Officer',
                'full_name' => $mda->code.' Officer',
                'sex' => 'female',
                'status' => 'active',
            ]);
            StaffEmployment::query()->create([
                'staff_id' => $staff->id,
                'mda_id' => $mda->id,
                'department_id' => $department->id,
                'cadre_id' => $cadre->id,
                'expected_retirement_date' => '2026-06-28',
                'employment_status' => 'active',
                'is_current' => true,
            ]);
            StaffSalaryPlacement::query()->create(['staff_id' => $staff->id, 'salary_scale_id' => $scale->id, 'level' => 9, 'step' => 1, 'is_current' => true]);
            StaffAllowanceAssignment::query()->create(['staff_id' => $staff->id, 'allowance_type_id' => $hazard->id, 'is_eligible' => true, 'source' => 'test']);
            StaffStatusHistory::query()->create(['staff_id' => $staff->id, 'status' => 'retired', 'effective_from' => '2025-04-01']);
        }

        $user = User::factory()->mdaUser($mdaA)->create();
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'mda',
            'mda_id' => $mdaB->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.counts.staff', 2)
            ->assertJsonPath('data.retirement_windows.this_month', 2);

        $labels = collect($response->json('data.distributions.departments'))->pluck('label')->all();
        $this->assertContains($mdaA->name.' Admin', $labels);
        $this->assertContains($mdaB->name.' Admin', $labels);
        $this->assertNotContains($mdaC->name.' Admin', $labels);

        CarbonImmutable::setTestNow();
    }

    public function test_dashboard_counts_overdue_expected_retirement_dates_as_retired(): void
    {
        CarbonImmutable::setTestNow('2026-06-13');

        $mda = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $department = Department::query()->create(['mda_id' => $mda->id, 'code' => 'ADM', 'name' => 'Admin', 'status' => 'active']);
        $scale = SalaryScale::query()->create(['mda_id' => $mda->id, 'code' => 'GL', 'name' => 'Grade Level', 'min_level' => 1, 'max_level' => 17, 'min_step' => 1, 'max_step' => 15, 'status' => 'active']);
        $cadre = Cadre::query()->create(['department_id' => $department->id, 'salary_scale_id' => $scale->id, 'name' => 'Admin Officer', 'status' => 'active']);

        $staff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'MOH-RET',
            'surname' => 'Retired',
            'first_name' => 'ByDate',
            'full_name' => 'Retired ByDate',
            'sex' => 'female',
            'status' => 'active',
        ]);

        StaffEmployment::query()->create([
            'staff_id' => $staff->id,
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'cadre_id' => $cadre->id,
            'expected_retirement_date' => '2011-07-01',
            'employment_status' => 'active',
            'is_current' => true,
        ]);

        $user = User::factory()->mdaUser($mda)->create();

        $this->actingAs($user)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.counts.staff', 1)
            ->assertJsonPath('data.counts.active_staff', 0)
            ->assertJsonPath('data.counts.retired_staff', 1);

        CarbonImmutable::setTestNow();
    }

    public function test_dashboard_retirement_history_falls_back_to_expected_retirement_date_when_no_retired_status_history_exists(): void
    {
        CarbonImmutable::setTestNow('2026-06-13');

        $mda = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $department = Department::query()->create(['mda_id' => $mda->id, 'code' => 'ADM', 'name' => 'Admin', 'status' => 'active']);
        $scale = SalaryScale::query()->create(['mda_id' => $mda->id, 'code' => 'GL', 'name' => 'Grade Level', 'min_level' => 1, 'max_level' => 17, 'min_step' => 1, 'max_step' => 15, 'status' => 'active']);
        $cadre = Cadre::query()->create(['department_id' => $department->id, 'salary_scale_id' => $scale->id, 'name' => 'Admin Officer', 'status' => 'active']);

        $staff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'MOH-HIST',
            'surname' => 'History',
            'first_name' => 'Fallback',
            'full_name' => 'History Fallback',
            'sex' => 'female',
            'status' => 'active',
        ]);

        StaffEmployment::query()->create([
            'staff_id' => $staff->id,
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'cadre_id' => $cadre->id,
            'expected_retirement_date' => '2025-04-01',
            'employment_status' => 'active',
            'is_current' => true,
        ]);

        $user = User::factory()->mdaUser($mda)->create();

        $response = $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

        $history = collect($response->json('data.retirement_trends.history'))->keyBy('label');

        $this->assertSame(1, $history->get('2025')['total']);

        CarbonImmutable::setTestNow();
    }
}
