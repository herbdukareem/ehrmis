<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetLine;
use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Budget\Services\BudgetGenerationService;
use App\Domain\Budget\Services\BudgetReportService;
use App\Domain\Movement\Models\MovementLine;
use App\Domain\Movement\Models\MovementSummary;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BudgetGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_budget_workbook_from_movement_summaries(): void
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

        $scale = SalaryScale::query()->firstOrCreate(['code' => 'GL'], [
            'name' => 'GRADE LEVEL',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        $movementWorkbook = MovementWorkbook::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'status' => 'approved',
            'summary' => ['lines_generated' => 2],
        ]);

        MovementSummary::query()->create([
            'workbook_id' => $movementWorkbook->id,
            'department_id' => $department->id,
            'salary_scale_id' => $scale->id,
            'level' => 9,
            'staff_count' => 3,
            'due_count' => 2,
            'retiring_count' => 1,
            'retired_count' => 0,
            'blocked_count' => 0,
            'current_gross_total' => 120000,
            'proposed_gross_total' => 150000,
            'variance_total' => 30000,
        ]);

        $budgetWorkbook = app(BudgetGenerationService::class)
            ->generateFromMovementWorkbook($movementWorkbook->fresh('summaries'));

        $this->assertInstanceOf(BudgetWorkbook::class, $budgetWorkbook);
        $this->assertSame('draft', $budgetWorkbook->status);
        $this->assertSame(1, BudgetLine::query()->where('workbook_id', $budgetWorkbook->id)->count());
        $this->assertSame(1, $budgetWorkbook->summary['line_count']);
        $this->assertSame(3, $budgetWorkbook->summary['staff_count']);
        $this->assertEquals(120000.0, $budgetWorkbook->summary['current_gross_total']);
        $this->assertEquals(150000.0, $budgetWorkbook->summary['proposed_gross_total']);
        $this->assertEquals(30000.0, $budgetWorkbook->summary['variance_total']);
    }

    public function test_it_generates_required_staff_from_post_movement_levels(): void
    {
        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $department = Department::query()->create([
            'mda_id' => $mda->id,
            'code' => 'MED',
            'name' => 'MEDICAL',
            'status' => 'active',
        ]);

        $scale = SalaryScale::query()->firstOrCreate(['code' => 'CH'], [
            'name' => 'CONHESS',
            'min_level' => 1,
            'max_level' => 15,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        $movementWorkbook = MovementWorkbook::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'budget_year' => 2027,
            'status' => 'approved',
        ]);

        $this->movementLine($movementWorkbook, $mda, $department, $scale, 'A001', 9, 10, 'active', 100, 150);
        $this->movementLine($movementWorkbook, $mda, $department, $scale, 'A002', 9, 9, 'retiring', 90, 90);
        $this->movementLine($movementWorkbook, $mda, $department, $scale, 'A003', 10, 10, 'active', 120, 130);
        $this->movementLine($movementWorkbook, $mda, $department, $scale, 'A004', 9, 9, 'retired', 80, 80);
        $this->movementLine($movementWorkbook, $mda, $department, $scale, 'A005', 9, 9, 'retired', 70, 75, isContractStaff: true);
        $this->movementLine($movementWorkbook, $mda, $department, $scale, 'A006', 9, 9, 'retired', 60, 65, isSpecialMovement: true);

        $budgetWorkbook = app(BudgetGenerationService::class)
            ->generateFromMovementWorkbook($movementWorkbook);

        $levelNine = BudgetLine::query()
            ->where('workbook_id', $budgetWorkbook->id)
            ->where('level', 9)
            ->firstOrFail();
        $levelTen = BudgetLine::query()
            ->where('workbook_id', $budgetWorkbook->id)
            ->where('level', 10)
            ->firstOrFail();

        $this->assertSame(4, $levelNine->staff_count);
        $this->assertSame(1, $levelNine->retiring_count);
        $this->assertSame(2, $levelNine->required_staff_count);
        $this->assertEquals(320.0, (float) $levelNine->current_gross_total);
        $this->assertEquals(140.0, (float) $levelNine->proposed_gross_total);

        $this->assertSame(1, $levelTen->staff_count);
        $this->assertSame(0, $levelTen->retiring_count);
        $this->assertSame(2, $levelTen->required_staff_count);
        $this->assertEquals(120.0, (float) $levelTen->current_gross_total);
        $this->assertEquals(280.0, (float) $levelTen->proposed_gross_total);
        $this->assertSame(4, $budgetWorkbook->summary['required_staff_count']);

        $levelNine->forceFill([
            'staff_count' => 5,
            'required_staff_count' => null,
            'current_gross_total' => 400,
            'proposed_gross_total' => 280,
        ])->save();
        $levelTen->forceFill(['required_staff_count' => null, 'proposed_gross_total' => 130])->save();

        $report = app(BudgetReportService::class)->build($budgetWorkbook, 'recurrent-expenditure');
        $rows = collect($report['groups'])->first()['rows'];

        $this->assertSame(2, collect($rows)->firstWhere('level', 9)['required_staff']);
        $this->assertSame(2, collect($rows)->firstWhere('level', 10)['required_staff']);
        $this->assertSame(4, collect($rows)->firstWhere('level', 9)['actual_staff']);
        $this->assertSame(1920.0, collect($rows)->firstWhere('level', 9)['actual_expense']);
        $this->assertSame(1680.0, collect($rows)->firstWhere('level', 9)['proposed_estimate']);
        $this->assertSame(3360.0, collect($rows)->firstWhere('level', 10)['proposed_estimate']);
        $this->assertSame(4, $report['grand_totals']['required_staff']);
    }

    public function test_it_is_idempotent_for_the_same_movement_workbook(): void
    {
        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $scale = SalaryScale::query()->firstOrCreate(['code' => 'GL'], [
            'name' => 'GRADE LEVEL',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        $movementWorkbook = MovementWorkbook::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'status' => 'approved',
        ]);

        MovementSummary::query()->create([
            'workbook_id' => $movementWorkbook->id,
            'department_id' => null,
            'salary_scale_id' => $scale->id,
            'level' => 8,
            'staff_count' => 1,
            'due_count' => 1,
            'retiring_count' => 0,
            'retired_count' => 0,
            'blocked_count' => 0,
            'current_gross_total' => 50000,
            'proposed_gross_total' => 55000,
            'variance_total' => 5000,
        ]);

        $service = app(BudgetGenerationService::class);

        $first = $service->generateFromMovementWorkbook($movementWorkbook->fresh('summaries'));
        $second = $service->generateFromMovementWorkbook($movementWorkbook->fresh('summaries'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BudgetWorkbook::query()->count());
        $this->assertSame(1, BudgetLine::query()->count());
    }

    public function test_it_requires_an_approved_or_locked_movement_workbook(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $movementWorkbook = MovementWorkbook::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'status' => 'draft',
        ]);

        app(BudgetGenerationService::class)->generateFromMovementWorkbook($movementWorkbook);
    }

    protected function movementLine(
        MovementWorkbook $workbook,
        Mda $mda,
        Department $department,
        SalaryScale $scale,
        string $staffNumber,
        int $currentLevel,
        int $proposedLevel,
        string $retirementStatus,
        float $currentGross,
        float $proposedGross,
        bool $isContractStaff = false,
        bool $isSpecialMovement = false,
    ): MovementLine {
        $staff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => $staffNumber,
            'surname' => $staffNumber,
            'first_name' => 'Officer',
            'full_name' => $staffNumber.' Officer',
            'status' => 'active',
        ]);

        $employment = $staff->employments()->create([
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'is_current' => true,
            'employment_status' => 'active',
        ]);

        return MovementLine::query()->create([
            'workbook_id' => $workbook->id,
            'staff_id' => $staff->id,
            'current_employment_id' => $employment->id,
            'current_salary_scale_id' => $scale->id,
            'proposed_salary_scale_id' => $scale->id,
            'selection_state' => $retirementStatus === 'retired' && ! $isContractStaff && ! $isSpecialMovement
                ? 'excluded'
                : 'included',
            'eligibility_status' => $currentLevel === $proposedLevel ? 'not_due' : 'due',
            'retirement_status' => $retirementStatus,
            'is_contract_staff' => $isContractStaff,
            'is_special_movement' => $isSpecialMovement,
            'current_level' => $currentLevel,
            'current_step' => 5,
            'proposed_level' => $proposedLevel,
            'proposed_step' => 6,
            'current_amounts' => ['calculated_gross' => $currentGross],
            'proposed_amounts' => ['calculated_gross' => $proposedGross],
        ]);
    }
}
