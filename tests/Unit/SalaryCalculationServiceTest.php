<?php

namespace Tests\Unit;

use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\SalaryStructureRateAllowance;
use App\Domain\Staff\Services\SalaryCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalaryCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_gross_from_dynamic_allowances_and_compares_legacy_gross(): void
    {
        $salaryScale = SalaryScale::query()->create([
            'code' => 'GL',
            'name' => 'GRADE LEVEL',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        $hazard = AllowanceType::query()->create([
            'code' => 'hazard',
            'name' => 'Hazard Allowance',
            'status' => 'active',
        ]);

        $shift = AllowanceType::query()->create([
            'code' => 'shift',
            'name' => 'Shift Allowance',
            'status' => 'active',
        ]);

        $rate = SalaryStructureRate::query()->create([
            'salary_scale_id' => $salaryScale->id,
            'level' => 9,
            'step' => 2,
            'basic_salary' => 50000.00,
            'legacy_gross_salary' => 56000.00,
            'status' => 'active',
        ]);

        SalaryStructureRateAllowance::query()->create([
            'salary_structure_rate_id' => $rate->id,
            'allowance_type_id' => $hazard->id,
            'amount' => 5000.00,
            'status' => 'active',
        ]);

        SalaryStructureRateAllowance::query()->create([
            'salary_structure_rate_id' => $rate->id,
            'allowance_type_id' => $shift->id,
            'amount' => 1000.00,
            'status' => 'active',
        ]);

        $service = app(SalaryCalculationService::class);
        $resolvedRate = $service->getRate('gl', 9, 2);
        $result = $service->calculateGrossForPlacement('GL', 9, 2, ['hazard', 'shift']);

        $this->assertNotNull($resolvedRate);
        $this->assertSame(50000.0, $result['basic_salary']);
        $this->assertSame(['hazard' => 5000.0, 'shift' => 1000.0], $result['allowance_breakdown']);
        $this->assertSame(6000.0, $result['total_allowances']);
        $this->assertSame(56000.0, $result['calculated_gross']);
        $this->assertSame(56000.0, $result['legacy_gross_salary']);
        $this->assertSame(0.0, $result['gross_difference']);
    }

    public function test_it_uses_only_selected_allowance_codes_and_treats_legacy_gross_as_reference(): void
    {
        $salaryScale = SalaryScale::query()->create([
            'code' => 'GL',
            'name' => 'GRADE LEVEL',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        $hazard = AllowanceType::query()->create([
            'code' => 'hazard',
            'name' => 'Hazard Allowance',
            'status' => 'active',
        ]);

        $shift = AllowanceType::query()->create([
            'code' => 'shift',
            'name' => 'Shift Allowance',
            'status' => 'active',
        ]);

        $rate = SalaryStructureRate::query()->create([
            'salary_scale_id' => $salaryScale->id,
            'level' => 10,
            'step' => 1,
            'basic_salary' => 50000.00,
            'legacy_gross_salary' => 56000.00,
            'status' => 'active',
        ]);

        SalaryStructureRateAllowance::query()->create([
            'salary_structure_rate_id' => $rate->id,
            'allowance_type_id' => $hazard->id,
            'amount' => 5000.00,
            'status' => 'active',
        ]);

        SalaryStructureRateAllowance::query()->create([
            'salary_structure_rate_id' => $rate->id,
            'allowance_type_id' => $shift->id,
            'amount' => 1000.00,
            'status' => 'active',
        ]);

        $result = app(SalaryCalculationService::class)->calculateGrossForPlacement('GL', 10, 1, ['hazard']);

        $this->assertSame(['hazard' => 5000.0], $result['allowance_breakdown']);
        $this->assertSame(5000.0, $result['total_allowances']);
        $this->assertSame(55000.0, $result['calculated_gross']);
        $this->assertSame(56000.0, $result['legacy_gross_salary']);
        $this->assertSame(-1000.0, $result['gross_difference']);
    }
}
