<?php

namespace App\Domain\Staff\Services;

use App\Domain\Staff\Models\QualificationScaleCeiling;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Support\UnifiedQualificationCatalog;
use Illuminate\Support\Facades\DB;

class QualificationCatalogSyncService
{
    /**
     * @return array<string, array{created: int, updated: int, skipped: int}>
     */
    public function syncAll(bool $seedSalaryScales = false): array
    {
        return DB::transaction(function () use ($seedSalaryScales): array {
            $summary = [
                'qualification_types' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
                'qualification_scale_ceilings' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
                'salary_scales' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            ];

            if ($seedSalaryScales) {
                $this->syncDefaultSalaryScales($summary['salary_scales']);
            }

            $this->syncQualificationTypes($summary['qualification_types']);
            $this->syncCeilings($summary['qualification_scale_ceilings']);

            return $summary;
        });
    }

    /**
     * @param  array{created: int, updated: int, skipped: int}|null  $summary
     */
    public function syncDefaultSalaryScalesForMda(int|object $mda, ?array &$summary = null): void
    {
        $this->syncDefaultSalaryScales($summary);
    }

    /**
     * @param  array{created: int, updated: int, skipped: int}|null  $summary
     */
    public function syncDefaultSalaryScales(?array &$summary = null): void
    {
        $summary ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach (UnifiedQualificationCatalog::salaryScales() as $code => $definition) {
            $scale = SalaryScale::query()
                ->withTrashed()
                ->where('code', $code)
                ->first();

            $wasRecentlyCreated = ! $scale;
            $scale ??= new SalaryScale();
            $scale->fill([
                'code' => $code,
                'name' => $definition['name'],
                'min_level' => $definition['min_level'],
                'max_level' => $definition['max_level'],
                'min_step' => $definition['min_step'],
                'max_step' => $definition['max_step'],
                'status' => 'active',
            ]);
            $scale->deleted_at = null;
            $scale->save();

            $this->recordMutation($summary, $wasRecentlyCreated);
        }
    }

    public function syncCeilingsForMda(int|object $mda): void
    {
        $this->syncQualificationTypes();

        SalaryScale::query()
            ->whereIn('code', array_keys(UnifiedQualificationCatalog::salaryScales()))
            ->each(fn (SalaryScale $scale) => $this->syncCeilingsForSalaryScale($scale));
    }

    /**
     * @param  array{created: int, updated: int, skipped: int}|null  $summary
     * @return array{created: int, updated: int, skipped: int}|null
     */
    public function syncCeilingsForSalaryScale(SalaryScale $salaryScale, ?array &$summary = null): ?array
    {
        $summary ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $scaleCode = UnifiedQualificationCatalog::normalizeSalaryScaleCode($salaryScale->code);

        if ($scaleCode === null || ! in_array($scaleCode, array_keys(UnifiedQualificationCatalog::salaryScales()), true)) {
            return $summary;
        }

        $this->syncQualificationTypes();

        foreach (UnifiedQualificationCatalog::ceilings() as $qualificationCode => $scaleCeilings) {
            $maxLevel = (int) ($scaleCeilings[$scaleCode] ?? 0);

            if ($maxLevel <= 0) {
                $summary['skipped']++;
                continue;
            }

            $qualificationType = QualificationType::query()
                ->where('code', $qualificationCode)
                ->first();

            if (! $qualificationType) {
                $summary['skipped']++;
                continue;
            }

            $ceiling = QualificationScaleCeiling::query()->updateOrCreate(
                [
                    'qualification_type_id' => $qualificationType->id,
                    'salary_scale_id' => $salaryScale->id,
                ],
                [
                    'max_level' => min($maxLevel, (int) $salaryScale->max_level),
                    'status' => 'active',
                ],
            );

            $this->recordMutation($summary, $ceiling->wasRecentlyCreated);
        }

        return $summary;
    }

    /**
     * @param  array{created: int, updated: int, skipped: int}|null  $summary
     */
    public function syncQualificationTypes(?array &$summary = null): void
    {
        $summary ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach (UnifiedQualificationCatalog::types() as $code => $definition) {
            $qualificationType = QualificationType::query()
                ->where('code', $code)
                ->first();

            $wasRecentlyCreated = ! $qualificationType;
            $qualificationType ??= new QualificationType();
            $qualificationType->fill([
                'code' => $code,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'status' => 'active',
            ]);
            $qualificationType->save();

            $this->recordMutation($summary, $wasRecentlyCreated);
        }
    }

    /**
     * @param  array{created: int, updated: int, skipped: int}|null  $summary
     */
    protected function syncCeilings(?array &$summary = null): void
    {
        $summary ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];

        SalaryScale::query()
            ->whereIn('code', array_keys(UnifiedQualificationCatalog::salaryScales()))
            ->orderBy('id')
            ->each(function (SalaryScale $salaryScale) use (&$summary): void {
                $this->syncCeilingsForSalaryScale($salaryScale, $summary);
            });
    }

    /**
     * @param  array{created: int, updated: int, skipped: int}  $summary
     */
    protected function recordMutation(array &$summary, bool $wasRecentlyCreated): void
    {
        if ($wasRecentlyCreated) {
            $summary['created']++;

            return;
        }

        $summary['updated']++;
    }
}
