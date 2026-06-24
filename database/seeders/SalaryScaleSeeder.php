<?php

namespace Database\Seeders;


use App\Domain\Staff\Models\SalaryScale;
use Illuminate\Database\Seeder;
use App\Domain\Organization\Models\Mda;

class SalaryScaleSeeder extends Seeder
{
    public function run(): void
    {
        $mda = Mda::query()->where('code', 'HMB')->firstOrFail();
        $mda_id = $mda->id;
        $salaryScales = [
            [
                'legacy_id' => 1,
                'code' => 'CM',
                'name' => 'CONMESS',
                'min_level' => 1,
                'max_level' => 8,
                'min_step' => 1,
                'max_step' => 11,
                'mda_id' => $mda_id,
            ],
            [
                'legacy_id' => 2,
                'code' => 'CH',
                'name' => 'CONHESS',
                'min_level' => 1,
                'max_level' => 15,
                'min_step' => 1,
                'max_step' => 15,
                'mda_id' => $mda_id,
            ],
            [
                'legacy_id' => 3,
                'code' => 'GL',
                'name' => 'GRADE LEVEL',
                'min_level' => 1,
                'max_level' => 17,
                'min_step' => 1,
                'max_step' => 15,
                'mda_id' => $mda_id,
            ],
            [
                'legacy_id' => 4,
                'code' => 'SG',
                'name' => 'SPECIAL GRADE',
                'min_level' => 1,
                'max_level' => 5,
                'min_step' => 1,
                'max_step' => 9,
                'mda_id' => $mda_id,
            ],
        ];

        foreach ($salaryScales as $salaryScaleData) {
            $salaryScale = SalaryScale::query()
                ->withTrashed()
                ->where('legacy_id', $salaryScaleData['legacy_id'])
                ->orWhere('code', $salaryScaleData['code'])
                ->firstOrNew();

            $salaryScale->fill([
                ...$salaryScaleData,
                'status' => 'active',
            ]);
            $salaryScale->deleted_at = null;
            $salaryScale->save();
        }
    }
}
