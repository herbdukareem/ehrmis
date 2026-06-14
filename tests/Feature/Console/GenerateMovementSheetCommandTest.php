<?php

namespace Tests\Feature\Console;

use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateMovementSheetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_generates_a_movement_workbook(): void
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

        $staff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'A001',
            'surname' => 'Active',
            'first_name' => 'User',
            'full_name' => 'Active User',
            'status' => 'active',
        ]);

        StaffEmployment::query()->create([
            'staff_id' => $staff->id,
            'mda_id' => $mda->id,
            'employment_status' => 'active',
            'is_current' => true,
        ]);

        StaffSalaryPlacement::query()->create([
            'staff_id' => $staff->id,
            'salary_scale_id' => $scale->id,
            'level' => 9,
            'step' => 2,
            'basic_salary' => 50000,
            'gross_salary' => 50000,
            'is_current' => true,
        ]);

        $this
            ->artisan('movement:generate-sheet', [
                'mda' => 'MOH',
                'year' => 2024,
            ])
            ->expectsOutputToContain('Movement workbook generated successfully.')
            ->assertSuccessful();

        $this->assertDatabaseHas('movement_workbooks', [
            'mda_id' => $mda->id,
            'year' => 2024,
        ]);
    }
}
