<?php

namespace Database\Seeders;

use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Services\AllowanceTypeProvisioningService;
use Illuminate\Database\Seeder;

class AllowanceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $firstMda = Mda::query()->orderBy('id')->first();

        if (! $firstMda) {
            return;
        }

        app(AllowanceTypeProvisioningService::class)->ensureForMda((int) $firstMda->id);
    }
}
