<?php

namespace Database\Seeders;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $mdas = [
            ['code' => 'DHCA', 'name' => 'DRUG & HOSPITAL CONSUMABLE AGENCY'],
            ['code' => 'HMB', 'name' => 'HOSPITAL MANAGEMENT BOARD'],
            ['code' => 'IBBSH', 'name' => 'IBB SPECIALIST HOSPITAL'],
            ['code' => 'MOH', 'name' => 'MINISTRY OF HEALTH'],
            ['code' => 'NSAC', 'name' => 'NIGER STATE AGENCY FOR CONTROL'],
            ['code' => 'NSCHA', 'name' => 'NIGER STATE CONT. HEALTH AGENCY'],
            ['code' => 'PHC', 'name' => 'PRIMARY HEALTHCARE'],
        ];

        foreach ($mdas as $mdaData) {
            Mda::query()->updateOrCreate(
                ['code' => $mdaData['code']],
                [
                    'name' => $mdaData['name'],
                    'status' => 'active',
                ],
            );
        }

        $departments = [
            ['code' => 'ADMIN', 'name' => 'ADMIN', 'description' => 'Administration department'],
            ['code' => 'MEDICAL', 'name' => 'Medical', 'description' => 'Medical department'],
            ['code' => 'PHARMACY', 'name' => 'Pharmacy', 'description' => 'Pharmacy department'],
            ['code' => 'NURSING', 'name' => 'Nursing', 'description' => 'Nursing department'],
            ['code' => 'LABORATORY', 'name' => 'Laboratory', 'description' => 'Laboratory department'],
            ['code' => 'PRS/HIM', 'name' => 'PRS/HIM', 'description' => 'PRS/HIM department'],
        ];

        Mda::query()->whereIn('code', collect($mdas)->pluck('code'))->each(function (Mda $mda) use ($departments): void {
            foreach ($departments as $deptData) {
                Department::query()->updateOrCreate(
                    ['mda_id' => $mda->id, 'code' => $deptData['code']],
                    [
                        'name' => $deptData['name'],
                        'description' => $deptData['description'],
                        'status' => 'active',
                    ],
                );
            }

            Station::query()->updateOrCreate(
                ['mda_id' => $mda->id, 'code' => 'HQ'],
                [
                    'name' => $mda->name.' Headquarters',
                    'description' => 'Headquarters station',
                    'status' => 'active',
                ],
            );
        });

        Location::query()->firstOrCreate(
            [
                'state' => 'Niger',
                'lga' => 'Chanchaga',
                'ward' => null,
                'town' => 'Minna',
            ],
            [
                'is_urban_center' => true,
                'status' => 'active',
            ],
        );
    }
}
