<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\QualificationScaleCeiling;
use Illuminate\Support\Str;

class QualificationCeilingService
{
    public function getMaxLevelFor(string $qualificationCode, string $salaryScaleCode): ?int
    {
        return QualificationScaleCeiling::query()
            ->where('status', 'active')
            ->whereHas('qualificationType', function ($query) use ($qualificationCode): void {
                $query
                    ->where('status', 'active')
                    ->where('code', $this->normalizeCode($qualificationCode));
            })
            ->whereHas('salaryScale', function ($query) use ($salaryScaleCode): void {
                $query
                    ->where('status', 'active')
                    ->where('code', $this->normalizeCode($salaryScaleCode));
            })
            ->value('max_level');
    }

    public function canMoveToLevel(string $qualificationCode, string $salaryScaleCode, int $targetLevel): bool
    {
        $maxLevel = $this->getMaxLevelFor($qualificationCode, $salaryScaleCode);

        return $maxLevel !== null && $targetLevel <= $maxLevel;
    }

    protected function normalizeCode(string $value): string
    {
        return Str::upper(Str::slug($value, '_'));
    }
}
