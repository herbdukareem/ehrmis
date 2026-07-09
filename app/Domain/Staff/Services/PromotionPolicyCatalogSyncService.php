<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\PromotionPolicy;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Support\PromotionPolicyCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PromotionPolicyCatalogSyncService
{
    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function syncAll(bool $overwriteExisting = true): array
    {
        return DB::transaction(function () use ($overwriteExisting): array {
            $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];
            $canonicalScales = $this->canonicalSalaryScales();

            foreach (PromotionPolicyCatalog::defaults() as $definition) {
                $salaryScale = $canonicalScales->get($definition['scale_code']);

                if (! $salaryScale) {
                    $summary['skipped']++;
                    continue;
                }

                $existingPolicy = PromotionPolicy::query()
                    ->where('min_level', $definition['min_level'])
                    ->where('max_level', $definition['max_level'])
                    ->where('policy_type', 'normal')
                    ->whereHas('salaryScale', fn ($query) => $query->where('code', $definition['scale_code']))
                    ->orderBy('id')
                    ->first();

                if ($existingPolicy && ! $overwriteExisting) {
                    $this->deduplicateRange($existingPolicy, $definition['scale_code']);
                    $summary['updated']++;
                    continue;
                }

                $policy = PromotionPolicy::query()->updateOrCreate(
                    [
                        'salary_scale_id' => $salaryScale->id,
                        'min_level' => $definition['min_level'],
                        'max_level' => $definition['max_level'],
                        'policy_type' => 'normal',
                    ],
                    [
                        'required_years' => $definition['required_years'],
                        'description' => 'Seeded from the built-in promotion policy catalog',
                        'status' => 'active',
                    ],
                );

                $this->deduplicateRange($policy, $definition['scale_code']);
                $summary[$policy->wasRecentlyCreated ? 'created' : 'updated']++;
            }

            return $summary;
        });
    }

    /**
     * @return Collection<string, SalaryScale>
     */
    public function canonicalSalaryScales(): Collection
    {
        return SalaryScale::query()
            ->whereIn('code', array_keys(PromotionPolicyCatalog::scaleOptions()))
            ->orderBy('id')
            ->get()
            ->groupBy(fn (SalaryScale $scale): string => PromotionPolicyCatalog::normalizeScaleCode($scale->code) ?? $scale->code)
            ->map(fn (Collection $scales): ?SalaryScale => $scales->first())
            ->filter();
    }

    protected function deduplicateRange(PromotionPolicy $keeper, string $scaleCode): void
    {
        PromotionPolicy::query()
            ->where('min_level', $keeper->min_level)
            ->where('max_level', $keeper->max_level)
            ->where('policy_type', $keeper->policy_type)
            ->whereHas('salaryScale', fn ($query) => $query->where('code', $scaleCode))
            ->whereKeyNot($keeper->id)
            ->delete();
    }
}
