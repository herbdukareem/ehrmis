<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetLine;
use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Budget\Services\BudgetGenerationService;
use App\Domain\Movement\Models\MovementSummary;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\SalaryScale;
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

        $scale = SalaryScale::query()->create([
            'code' => 'GL',
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

    public function test_it_is_idempotent_for_the_same_movement_workbook(): void
    {
        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $scale = SalaryScale::query()->create([
            'code' => 'GL',
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
}
