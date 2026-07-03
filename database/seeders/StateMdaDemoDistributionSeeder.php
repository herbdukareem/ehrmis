<?php

namespace Database\Seeders;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\MdaSetting;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffAllowanceAssignment;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Domain\Staff\Services\AllowanceTypeProvisioningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StateMdaDemoDistributionSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string}>
     */
    protected array $mdas = [
        ['code' => 'DHCA', 'name' => 'DRUG & HOSPITAL CONSUMABLE AGENCY'],
        ['code' => 'HMB', 'name' => 'HOSPITAL MANAGEMENT BOARD'],
        ['code' => 'IBBSH', 'name' => 'IBB SPECIALIST HOSPITAL'],
        ['code' => 'MOH', 'name' => 'MINISTRY OF HEALTH'],
        ['code' => 'NSAC', 'name' => 'NIGER STATE AGENCY FOR CONTROL'],
        ['code' => 'NSCHA', 'name' => 'NIGER STATE CONT. HEALTH AGENCY'],
        ['code' => 'PHC', 'name' => 'PRIMARY HEALTHCARE'],
    ];

    /**
     * @var array<int, array{code: string, name: string}>
     */
    protected array $departments = [
        ['code' => 'ADMIN', 'name' => 'ADMIN'],
        ['code' => 'MEDICAL', 'name' => 'Medical'],
        ['code' => 'PHARMACY', 'name' => 'Pharmacy'],
        ['code' => 'NURSING', 'name' => 'Nursing'],
        ['code' => 'LABORATORY', 'name' => 'Laboratory'],
        ['code' => 'PRS/HIM', 'name' => 'PRS/HIM'],
    ];

    public function run(): void
    {
        $mdaRecords = collect($this->mdas)
            ->map(fn (array $mda): Mda => $this->ensureMda($mda))
            ->values();

        $catalog = $mdaRecords->mapWithKeys(fn (Mda $mda): array => [$mda->id => $this->ensureSetupForMda($mda)]);

        $staff = Staff::withoutGlobalScopes()
            ->with(['currentEmployment', 'currentSalaryPlacement'])
            ->orderBy('id')
            ->get();

        if ($staff->isEmpty()) {
            $staff = $this->createDemoStaff($mdaRecords->first(), 70);
        }

        $staff = $staff
            ->sortBy(fn (Staff $record): int => crc32($record->id.'|state-mda-demo-distribution'))
            ->values();

        foreach ($staff as $index => $record) {
            $mda = $mdaRecords[$index % $mdaRecords->count()];
            $setup = $catalog->get($mda->id);
            $department = $setup['departments'][$index % $setup['departments']->count()];
            $station = $setup['stations'][$index % $setup['stations']->count()];
            $cadre = $setup['cadres']->firstWhere('department_id', $department->id) ?? $setup['cadres']->first();
            $cadreRanks = $setup['ranks']->where('cadre_id', $cadre?->id)->values();
            $rank = $cadreRanks->isNotEmpty()
                ? $cadreRanks[$index % $cadreRanks->count()]
                : $setup['ranks']->first();
            $scale = $rank?->salaryScale ?? $setup['salary_scales']->firstWhere('code', 'GL') ?? $setup['salary_scales']->first();

            $level = (int) ($rank?->level ?? (($index % 12) + 5));
            $step = ($index % 15) + 1;

            $record->forceFill([
                'mda_id' => $mda->id,
                'staff_number' => $record->staff_number ?: $mda->code.'-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT),
                'status' => $record->status ?: 'active',
            ])->save();

            $employment = $record->currentEmployment;
            $employmentData = [
                'staff_id' => $record->id,
                'mda_id' => $mda->id,
                'department_id' => $department->id,
                'station_id' => $station->id,
                'cadre_id' => $cadre?->id,
                'rank_id' => $rank?->id,
                'staff_category' => $employment?->staff_category ?? 'Civil Service',
                'date_first_appointment' => $employment?->date_first_appointment ?? now()->subYears(8 + ($index % 18))->toDateString(),
                'date_last_promotion' => $employment?->date_last_promotion ?? now()->subYears(2 + ($index % 5))->toDateString(),
                'expected_retirement_date' => now()->addMonths(($index % 72) - 12)->toDateString(),
                'employment_status' => $record->status === 'retired' ? 'retired' : 'active',
                'is_current' => true,
                'effective_from' => $employment?->effective_from ?? now()->subYears(2)->toDateString(),
            ];

            $employment
                ? $employment->forceFill($employmentData)->save()
                : StaffEmployment::query()->create($employmentData);

            $placement = $record->currentSalaryPlacement;
            $placementData = [
                'staff_id' => $record->id,
                'salary_scale_id' => $scale?->id,
                'level' => $level,
                'step' => $step,
                'source' => 'state_demo_distribution',
                'is_current' => true,
                'effective_from' => $placement?->effective_from ?? now()->subYears(2)->toDateString(),
            ];

            $placement
                ? $placement->forceFill($placementData)->save()
                : StaffSalaryPlacement::query()->create($placementData);

            $this->alignAllowanceAssignments($record, $setup['allowance_types']);
        }
    }

    protected function ensureMda(array $mdaData): Mda
    {
        $mda = Mda::query()->updateOrCreate(
            ['code' => $mdaData['code']],
            ['name' => $mdaData['name'], 'status' => 'active'],
        );

        MdaSetting::query()->firstOrCreate(
            ['mda_id' => $mda->id],
            [
                'acronym' => $mda->code,
                'domain' => Str::slug(strtolower($mda->code)).'-ehrmis.test',
                'email' => strtolower($mda->code).'@nigerstate.gov.ng',
            ],
        );

        return $mda;
    }

    protected function ensureSetupForMda(Mda $mda): array
    {
        $departments = collect($this->departments)
            ->map(fn (array $department): Department => Department::query()->updateOrCreate(
                ['mda_id' => $mda->id, 'code' => $department['code']],
                ['name' => $department['name'], 'description' => $department['name'].' department', 'status' => 'active'],
            ))
            ->values();

        $stations = collect(['HQ' => 'Headquarters', 'FIELD' => 'Field Office', 'CLINIC' => 'Service Centre'])
            ->map(fn (string $name, string $code): Station => Station::query()->updateOrCreate(
                ['mda_id' => $mda->id, 'code' => $code],
                ['name' => $mda->name.' '.$name, 'description' => $name.' station', 'status' => 'active'],
            ))
            ->values();

        $salaryScales = collect([
            ['code' => 'CM', 'name' => 'CONMESS', 'min_level' => 1, 'max_level' => 8, 'min_step' => 1, 'max_step' => 11],
            ['code' => 'CH', 'name' => 'CONHESS', 'min_level' => 1, 'max_level' => 15, 'min_step' => 1, 'max_step' => 15],
            ['code' => 'GL', 'name' => 'GRADE LEVEL', 'min_level' => 1, 'max_level' => 17, 'min_step' => 1, 'max_step' => 15],
            ['code' => 'SG', 'name' => 'SPECIAL GRADE', 'min_level' => 1, 'max_level' => 5, 'min_step' => 1, 'max_step' => 9],
        ])->map(fn (array $scale): SalaryScale => SalaryScale::query()->updateOrCreate(
            ['mda_id' => $mda->id, 'code' => $scale['code']],
            $scale + ['mda_id' => $mda->id, 'status' => 'active'],
        ))->values();

        $cadres = $departments->map(function (Department $department) use ($salaryScales): Cadre {
            $scaleCode = match ($department->code) {
                'MEDICAL' => 'CM',
                'PHARMACY', 'NURSING', 'LABORATORY' => 'CH',
                default => 'GL',
            };
            $scale = $salaryScales->firstWhere('code', $scaleCode) ?? $salaryScales->first();
            $name = match ($department->code) {
                'MEDICAL' => 'Medical Officer',
                'PHARMACY' => 'Pharmacist',
                'NURSING' => 'Nursing Officer',
                'LABORATORY' => 'Laboratory Scientist',
                'PRS/HIM' => 'Planning Officer',
                default => 'Administrative Officer',
            };

            return Cadre::query()->updateOrCreate(
                ['department_id' => $department->id, 'name' => $name, 'salary_scale_id' => $scale?->id],
                ['status' => 'active'],
            );
        })->values();

        $ranks = $cadres->flatMap(function (Cadre $cadre) {
            return collect(range(6, 12))->map(fn (int $level): Rank => Rank::query()->updateOrCreate(
                [
                    'cadre_id' => $cadre->id,
                    'salary_scale_id' => $cadre->salary_scale_id,
                    'name' => $cadre->name.' Level '.$level,
                    'level' => $level,
                ],
                ['status' => 'active'],
            ));
        })->values();

        $ranks->each->load('salaryScale');

        $allowanceTypes = app(AllowanceTypeProvisioningService::class)->ensureForMda((int) $mda->id)['types'];

        return [
            'departments' => $departments,
            'stations' => $stations,
            'salary_scales' => $salaryScales,
            'cadres' => $cadres,
            'ranks' => $ranks,
            'allowance_types' => $allowanceTypes,
        ];
    }

    protected function createDemoStaff(Mda $mda, int $count): Collection
    {
        return collect(range(1, $count))->map(fn (int $number): Staff => Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'DEMO-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'surname' => 'Demo',
            'first_name' => 'Officer '.$number,
            'full_name' => 'Demo Officer '.$number,
            'sex' => $number % 2 === 0 ? 'female' : 'male',
            'status' => 'active',
        ]));
    }

    protected function alignAllowanceAssignments(Staff $staff, Collection $allowanceTypes): void
    {
        $defaultAllowance = $allowanceTypes->firstWhere('code', 'hazard') ?? $allowanceTypes->first();

        if (! $defaultAllowance) {
            return;
        }

        StaffAllowanceAssignment::query()->updateOrCreate(
            [
                'staff_id' => $staff->id,
                'allowance_type_id' => $defaultAllowance->id,
                'source' => 'state_demo_distribution',
            ],
            [
                'is_eligible' => true,
                'effective_from' => now()->subYear()->toDateString(),
                'effective_to' => null,
            ],
        );
    }
}
