<?php

namespace Database\Seeders;


use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Support\UnifiedQualificationCatalog;
use Illuminate\Database\Seeder;

class SalaryScaleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (UnifiedQualificationCatalog::salaryScales() as $code => $definition) {
            $salaryScale = SalaryScale::withTrashed()
                ->where('code', $code)
                ->firstOrNew();

            $salaryScale->fill([
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
    }
}
