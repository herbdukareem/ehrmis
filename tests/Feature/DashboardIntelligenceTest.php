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
        $scale = SalaryScale::query()->create(['code' => 'GL', 'name' => 'Grade Level', 'min_level' => 1, 'max_level' => 17, 'min_step' => 1, 'max_step' => 15, 'status' => 'active']);
        $cadre = Cadre::query()->create(['department_id' => $department->id, 'salary_scale_id' => $scale->id, 'name' => 'Medical Officer', 'status' => 'active']);
        $hazard = AllowanceType::query()->create(['code' => 'hazard', 'name' => 'Hazard Allowance', 'status' => 'active']);

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
}
