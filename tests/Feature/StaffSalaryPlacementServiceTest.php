<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\SalaryStructureRateAllowance;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Domain\Staff\Services\StaffSalaryPlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffSalaryPlacementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_placement_update_uses_salary_calculation_service_and_closes_old_current(): void
    {
        $mda = Mda::query()->create(['code' => 'MOH', 'name' => 'MINISTRY OF HEALTH', 'status' => 'active']);

        $salaryScale = SalaryScale::query()->create([
            'mda_id' => $mda->id,
            'code' => 'GL',
            'name' => 'GRADE LEVEL',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        $hazard = AllowanceType::query()->create([
            'mda_id' => $mda->id,
            'code' => 'hazard',
            'name' => 'Hazard Allowance',
            'status' => 'active',
        ]);

        $oldRate = SalaryStructureRate::query()->create([
            'mda_id' => $mda->id,
            'salary_scale_id' => $salaryScale->id,
            'level' => 8,
            'step' => 2,
            'basic_salary' => 40000,
            'legacy_gross_salary' => 44000,
            'status' => 'active',
        ]);

        $newRate = SalaryStructureRate::query()->create([
            'mda_id' => $mda->id,
            'salary_scale_id' => $salaryScale->id,
            'level' => 9,
            'step' => 2,
            'basic_salary' => 50000,
            'legacy_gross_salary' => 56000,
            'status' => 'active',
        ]);

        SalaryStructureRateAllowance::query()->create([
            'mda_id' => $mda->id,
            'salary_structure_rate_id' => $oldRate->id,
            'allowance_type_id' => $hazard->id,
            'amount' => 4000,
            'status' => 'active',
        ]);

        SalaryStructureRateAllowance::query()->create([
            'mda_id' => $mda->id,
            'salary_structure_rate_id' => $newRate->id,
            'allowance_type_id' => $hazard->id,
            'amount' => 6000,
            'status' => 'active',
        ]);

        $staff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'STF001',
            'surname' => 'Salary',
            'first_name' => 'User',
            'full_name' => 'Salary User',
            'status' => 'active',
        ]);

        StaffAllowanceAssignment::query()->create([
            'staff_id' => $staff->id,
            'allowance_type_id' => $hazard->id,
            'is_eligible' => true,
            'source' => 'legacy_import',
        ]);

        $oldPlacement = StaffSalaryPlacement::query()->create([
            'staff_id' => $staff->id,
            'salary_scale_id' => $salaryScale->id,
            'level' => 8,
            'step' => 2,
            'basic_salary' => 44000,
            'gross_salary' => 44000,
            'is_current' => true,
            'effective_from' => '2020-01-01',
        ]);

        $placement = app(StaffSalaryPlacementService::class)->createPlacement($staff, [
            'salary_scale' => $salaryScale,
            'level' => 9,
            'step' => 2,
            'effective_from' => '2026-01-01',
        ]);

        $this->assertFalse($oldPlacement->fresh()->is_current);
        $this->assertSame('2026-01-01', optional($oldPlacement->fresh()->effective_to)->toDateString());
        $this->assertTrue($placement->is_current);
        $this->assertEquals(50000.0, (float) $placement->basic_salary_snapshot);
        $this->assertEquals(56000.0, (float) $placement->legacy_gross_salary_snapshot);
        $this->assertEquals(56000.0, (float) $placement->calculated_gross_salary_snapshot);
        $this->assertEquals(0.0, (float) $placement->gross_difference_snapshot);
        $this->assertDatabaseHas('audit_logs', [
            'event_code' => 'staff.salary_placement.updated',
            'auditable_type' => Staff::class,
            'auditable_id' => $staff->id,
        ]);
    }
}
