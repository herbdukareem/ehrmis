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
            ['code' => 'HMB', 'name' => 'HOSPITAL MANAGEMENT BOARD'],
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

        $hmb = Mda::query()->where('code', 'HMB')->firstOrFail();

        $departments = [
            ['code' => 'ADMIN', 'name' => 'ADMIN', 'description' => 'Administration department'],
            ['code' => 'MEDICAL', 'name' => 'Medical', 'description' => 'Medical department'],
            ['code' => 'PHARMACY', 'name' => 'Pharmacy', 'description' => 'Pharmacy department'],
            ['code' => 'NURSING', 'name' => 'Nursing', 'description' => 'Nursing department'],
            ['code' => 'LABORATORY', 'name' => 'Laboratory', 'description' => 'Laboratory department'],
            ['code' => 'PRS/HIM', 'name' => 'PRS/HIM', 'description' => 'PRS/HIM department'],
        ];

        foreach ($departments as $deptData) {
            Department::query()->updateOrCreate(
                ['mda_id' => $hmb->id, 'code' => $deptData['code']],
                [
                    'name' => $deptData['name'],
                    'description' => $deptData['description'],
                    'status' => 'active',
                ],
            );
        }

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
