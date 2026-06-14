<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\PromotionPolicy;
use Carbon\Carbon;
use Illuminate\Support\Str;

class PromotionPolicyService
{
    protected array $requiredYearsCache = [];

    public function getRequiredYears(string $salaryScaleCode, int $currentLevel): ?int
    {
        $normalizedCode = $this->normalizeCode($salaryScaleCode);
        $cacheKey = $normalizedCode.'|'.$currentLevel;

        if (array_key_exists($cacheKey, $this->requiredYearsCache)) {
            return $this->requiredYearsCache[$cacheKey];
        }

        return $this->requiredYearsCache[$cacheKey] = PromotionPolicy::query()
            ->where('status', 'active')
            ->where('policy_type', 'normal')
            ->where('min_level', '<=', $currentLevel)
            ->where('max_level', '>=', $currentLevel)
            ->whereHas('salaryScale', function ($query) use ($normalizedCode): void {
                $query
                    ->where('status', 'active')
                    ->where('code', $normalizedCode);
            })
            ->orderBy('min_level')
            ->value('required_years');
    }

    public function calculateNextPromotionDate(Carbon $lastPromotionDate, string $salaryScaleCode, int $currentLevel): ?Carbon
    {
        $requiredYears = $this->getRequiredYears($salaryScaleCode, $currentLevel);

        if ($requiredYears === null) {
            return null;
        }

        return $lastPromotionDate->copy()->addYears($requiredYears);
    }

    public function isPromotionDue(Carbon $lastPromotionDate, string $salaryScaleCode, int $currentLevel, Carbon $asOfDate): bool
    {
        $nextPromotionDate = $this->calculateNextPromotionDate($lastPromotionDate, $salaryScaleCode, $currentLevel);

        return $nextPromotionDate !== null && $nextPromotionDate->lte($asOfDate);
    }

    protected function normalizeCode(string $value): string
    {
        return Str::upper(Str::slug($value, '_'));
    }
}
