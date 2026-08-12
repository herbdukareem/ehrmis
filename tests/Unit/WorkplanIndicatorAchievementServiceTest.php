<?php

namespace Tests\Unit;

use App\Domain\Workplan\Models\WorkplanIndicator;
use App\Domain\Workplan\Services\WorkplanIndicatorAchievementService;
use Tests\TestCase;

class WorkplanIndicatorAchievementServiceTest extends TestCase
{
    public function test_it_calculates_increasing_and_overachievement_ratios(): void
    {
        $indicator = new WorkplanIndicator(['target_mode'=>'absolute','direction'=>'increase']);
        $service = app(WorkplanIndicatorAchievementService::class);
        $this->assertSame(0.9, $service->ratio($indicator, 50, 45));
        $this->assertSame(1.3, $service->ratio($indicator, 100, 130));
    }

    public function test_it_handles_decreasing_milestone_and_missing_values_safely(): void
    {
        $service = app(WorkplanIndicatorAchievementService::class);
        $decrease = new WorkplanIndicator(['target_mode'=>'absolute','direction'=>'decrease']);
        $milestone = new WorkplanIndicator(['target_mode'=>'milestone','direction'=>'milestone']);
        $this->assertSame(2.0, $service->ratio($decrease, 10, 5));
        $this->assertSame(1.0, $service->ratio($milestone, 1, 1));
        $this->assertSame(0.0, $service->ratio($milestone, 1, 0));
        $this->assertNull($service->ratio($decrease, 0, 5));
        $this->assertNull($service->ratio($decrease, 10, null));
    }
}
