<?php

namespace Database\Seeders;


use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Support\UnifiedQualificationCatalog;
use Illuminate\Database\Seeder;
use App\Domain\Organization\Models\Mda;

class SalaryScaleSeeder extends Seeder
{
    public function run(): void
    {
        Mda::query()->orderBy('id')->each(function (Mda $mda): void {
            foreach (UnifiedQualificationCatalog::salaryScales() as $code => $definition) {
                $salaryScale = SalaryScale::withoutGlobalScopes()
                    ->withTrashed()
                    ->where('mda_id', $mda->id)
                    ->where('code', $code)
                    ->firstOrNew();

                $salaryScale->fill([
                    'mda_id' => $mda->id,
                    'code' => $code,
                    'name' => $definition['name'],
                    'min_level' => $definition['min_level'],
                    'max_level' => $definition['max_level'],
                    'min_step' => $definition['min_step'],
                    'max_step' => $definition['max_step'],
                    'status' => 'active',
                ]);
                $salaryScale->deleted_at = null;
                $salaryScale->save();
            }
        });
    }
}
