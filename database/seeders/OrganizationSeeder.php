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
        $mda = Mda::query()->firstOrCreate(
            ['code' => 'MOH'],
            [
                'name' => 'Ministry of Health',
                'description' => 'Sample Ministry of Health record for Phase 1.',
                'status' => 'active',
            ],
        );

        Department::query()->firstOrCreate(
            ['mda_id' => $mda->id, 'code' => 'ADMIN'],
            [
                'name' => 'Administration',
                'description' => 'Administration department',
                'status' => 'active',
            ],
        );

        Station::query()->firstOrCreate(
            ['mda_id' => $mda->id, 'code' => 'HQ'],
            [
                'name' => 'Headquarters',
                'description' => 'Primary headquarters station',
                'status' => 'active',
            ],
        );

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
