<?php

namespace Tests\Unit;

use App\Domain\Staff\Models\QualificationScaleCeiling;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Services\QualificationCeilingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualificationCeilingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_expected_max_level_for_a_qualification_and_scale(): void
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

        $qualificationType = QualificationType::query()->create([
            'code' => 'PHD',
            'name' => 'PhD',
            'status' => 'active',
        ]);

        QualificationScaleCeiling::query()->create([
            'qualification_type_id' => $qualificationType->id,
            'salary_scale_id' => $salaryScale->id,
            'max_level' => 17,
            'status' => 'active',
        ]);

        $service = app(QualificationCeilingService::class);

        $this->assertSame(17, $service->getMaxLevelFor('PhD', 'gl'));
        $this->assertTrue($service->canMoveToLevel('PHD', 'GL', 16));
        $this->assertFalse($service->canMoveToLevel('PHD', 'GL', 18));
    }
}
