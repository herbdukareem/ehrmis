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
            ['code' => 'SACA', 'name' => 'NIGER STATE AGENCY FOR CONTROL'],
            ['code' => 'NICARE', 'name' => 'NIGER STATE CONT. HEALTH AGENCY'],
            ['code' => 'PHC', 'name' => 'PRIMARY HEALTHCARE'],
            ['code' => 'NSPHFA', 'name' => 'NIGER STATE PRIVATE HEALTH FACILITIES AGENCY'],
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

       

       
    }
}
