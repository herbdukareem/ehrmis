<?php

namespace Tests\Feature;

use App\Domain\Movement\Models\MovementLine;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementDepartmentSummaryService;
use App\Domain\Movement\Services\MovementSheetGenerationService;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\PromotionPolicy;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\SalaryStructureRateAllowance;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Domain\Organization\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovementSheetGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_movement_lines_using_salary_calculation_service(): void
    {
        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $department = Department::query()->create([
            'mda_id' => $mda->id,
            'code' => 'ADMIN',
            'name' => 'ADMIN',
            'status' => 'active',
        ]);

        $salaryScale = SalaryScale::query()->create([
            'code' => 'GL',
            'name' => 'GRADE LEVEL',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        PromotionPolicy::query()->create([
            'salary_scale_id' => $salaryScale->id,
            'min_level' => 1,
            'max_level' => 14,
            'required_years' => 3,
            'policy_type' => 'normal',
            'status' => 'active',
        ]);

        $hazard = AllowanceType::query()->create([
            'code' => 'hazard',
            'name' => 'Hazard Allowance',
            'status' => 'active',
        ]);

        $levelNine = SalaryStructureRate::query()->create([
            'salary_scale_id' => $salaryScale->id,
            'level' => 9,
            'step' => 2,
            'basic_salary' => 50000,
            'legacy_gross_salary' => 55000,
            'status' => 'active',
        ]);

        $levelTen = SalaryStructureRate::query()->create([
            'salary_scale_id' => $salaryScale->id,
            'level' => 10,
            'step' => 2,
            'basic_salary' => 60000,
            'legacy_gross_salary' => 66000,
            'status' => 'active',
        ]);

        $levelTenBudgetStep = SalaryStructureRate::query()->create([
            'salary_scale_id' => $salaryScale->id,
            'level' => 10,
            'step' => 5,
            'basic_salary' => 70000,
            'legacy_gross_salary' => 77000,
            'status' => 'active',
        ]);

        SalaryStructureRateAllowance::query()->create([
            'salary_structure_rate_id' => $levelNine->id,
            'allowance_type_id' => $hazard->id,
            'amount' => 5000,
            'status' => 'active',
        ]);

        SalaryStructureRateAllowance::query()->create([
            'salary_structure_rate_id' => $levelTen->id,
            'allowance_type_id' => $hazard->id,
            'amount' => 6000,
            'status' => 'active',
        ]);

        SalaryStructureRateAllowance::query()->create([
            'salary_structure_rate_id' => $levelTenBudgetStep->id,
            'allowance_type_id' => $hazard->id,
            'amount' => 7000,
            'status' => 'active',
        ]);

        $activeStaff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'A001',
            'surname' => 'Active',
            'first_name' => 'User',
            'full_name' => 'Active User',
            'status' => 'active',
            'date_of_birth' => '1985-01-01',
        ]);

        StaffEmployment::query()->create([
            'staff_id' => $activeStaff->id,
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'date_first_appointment' => '2010-01-01',
            'date_last_promotion' => '2022-01-01',
            'expected_retirement_date' => '2045-01-01',
            'employment_status' => 'active',
            'is_current' => true,
        ]);

        $activePlacement = StaffSalaryPlacement::query()->create([
            'staff_id' => $activeStaff->id,
            'salary_scale_id' => $salaryScale->id,
            'level' => 9,
            'step' => 2,
            'basic_salary' => 50000,
            'gross_salary' => 55000,
            'is_current' => true,
        ]);

        StaffAllowanceAssignment::query()->create([
            'staff_id' => $activeStaff->id,
            'allowance_type_id' => $hazard->id,
            'is_eligible' => true,
            'source' => 'test',
        ]);

        $retiredStaff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'R001',
            'surname' => 'Retired',
            'first_name' => 'User',
            'full_name' => 'Retired User',
            'status' => 'retired',
            'date_of_birth' => '1960-01-01',
        ]);

        StaffEmployment::query()->create([
            'staff_id' => $retiredStaff->id,
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'date_first_appointment' => '1990-01-01',
            'date_last_promotion' => '2020-01-01',
            'expected_retirement_date' => '2023-06-01',
            'employment_status' => 'retired',
            'is_current' => true,
        ]);

        StaffSalaryPlacement::query()->create([
            'staff_id' => $retiredStaff->id,
            'salary_scale_id' => $salaryScale->id,
            'level' => 9,
            'step' => 2,
            'basic_salary' => 50000,
            'gross_salary' => 55000,
            'is_current' => true,
        ]);

        $workbook = app(MovementSheetGenerationService::class)->generateForMda(
            $mda->id,
            2024,
            null,
            '2024 Movement Sheet',
            2025,
            5,
        );

        $this->assertInstanceOf(MovementWorkbook::class, $workbook);
        $this->assertSame(2, MovementLine::query()->where('workbook_id', $workbook->id)->count());
        $this->assertSame(1, $workbook->summary['due_for_promotion']);
        $this->assertSame(1, $workbook->summary['already_retired']);
        $this->assertDatabaseHas('movement_summaries', [
            'workbook_id' => $workbook->id,
            'department_id' => $department->id,
            'salary_scale_id' => $salaryScale->id,
            'level' => 9,
            'staff_count' => 2,
            'due_count' => 1,
            'retired_count' => 1,
        ]);

        $activeLine = MovementLine::query()
            ->where('workbook_id', $workbook->id)
            ->where('staff_id', $activeStaff->id)
            ->firstOrFail();

        $retiredLine = MovementLine::query()
            ->where('workbook_id', $workbook->id)
            ->where('staff_id', $retiredStaff->id)
            ->firstOrFail();

        $this->assertSame($activePlacement->id, $activeLine->current_salary_placement_id);
        $this->assertSame('due', $activeLine->eligibility_status);
        $this->assertSame(9, $activeLine->current_level);
        $this->assertSame(10, $activeLine->proposed_level);
        $this->assertSame(5, $activeLine->proposed_step);
        $this->assertEquals(55000.0, $activeLine->current_amounts['calculated_gross']);
        $this->assertEquals(77000.0, $activeLine->proposed_amounts['calculated_gross']);

        $this->assertSame('retired', $retiredLine->retirement_status);
        $this->assertSame('excluded', $retiredLine->selection_state);
        $this->assertSame('retired', $retiredLine->eligibility_status);

        $departmentSummary = app(MovementDepartmentSummaryService::class)->summarize($workbook)->first();
        $levelNineSummary = collect($departmentSummary['rows'])->firstWhere('level', 9);
        $levelTenSummary = collect($departmentSummary['rows'])->firstWhere('level', 10);
        $levelSeventeenSummary = collect($departmentSummary['rows'])->firstWhere('level', 17);

        $this->assertCount(17, $departmentSummary['rows']);
        $this->assertSame(0, $levelSeventeenSummary['expected_total']);
        $this->assertSame(2, $levelNineSummary['present_staff']);
        $this->assertSame(1, $levelNineSummary['staff_moving']);
        $this->assertSame(1, $levelNineSummary['staff_retiring']);
        $this->assertSame(0, $levelNineSummary['expected_total']);
        $this->assertSame(0, $levelTenSummary['present_staff']);
        $this->assertSame(1, $levelTenSummary['staff_joining']);
        $this->assertSame(1, $levelTenSummary['expected_total']);
    }
}
