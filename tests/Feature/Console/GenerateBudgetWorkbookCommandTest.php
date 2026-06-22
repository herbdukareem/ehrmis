<?php

namespace Tests\Feature\Console;

use App\Domain\Movement\Models\MovementSummary;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\SalaryScale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateBudgetWorkbookCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_generates_a_budget_workbook(): void
    {
        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $scale = SalaryScale::query()->create([
            'mda_id' => $mda->id,
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
            'level' => 9,
            'staff_count' => 2,
            'due_count' => 1,
            'retiring_count' => 0,
            'retired_count' => 0,
            'blocked_count' => 0,
            'current_gross_total' => 100000,
            'proposed_gross_total' => 112000,
            'variance_total' => 12000,
        ]);

        $this
            ->artisan('budget:generate-workbook', [
                'movementWorkbook' => $movementWorkbook->id,
            ])
            ->expectsOutputToContain('Budget workbook generated successfully.')
            ->assertSuccessful();

        $this->assertDatabaseHas('budget_workbooks', [
            'movement_workbook_id' => $movementWorkbook->id,
            'mda_id' => $mda->id,
            'year' => 2026,
        ]);
    }
}
