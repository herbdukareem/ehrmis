<?php

namespace Tests\Unit;

use App\Domain\Staff\Models\PromotionPolicy;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Services\PromotionPolicyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_required_years_and_calculates_promotion_dates(): void
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

        PromotionPolicy::query()->create([
            'salary_scale_id' => $salaryScale->id,
            'min_level' => 7,
            'max_level' => 14,
            'required_years' => 3,
            'policy_type' => 'normal',
            'status' => 'active',
        ]);

        $service = app(PromotionPolicyService::class);
        $lastPromotionDate = Carbon::parse('2021-01-01');

        $this->assertSame(3, $service->getRequiredYears('gl', 10));
        $this->assertTrue($service->calculateNextPromotionDate($lastPromotionDate, 'GL', 10)?->equalTo(Carbon::parse('2024-01-01')));
        $this->assertTrue($service->isPromotionDue($lastPromotionDate, 'GL', 10, Carbon::parse('2024-01-01')));
        $this->assertFalse($service->isPromotionDue($lastPromotionDate, 'GL', 10, Carbon::parse('2023-12-31')));
    }
}
