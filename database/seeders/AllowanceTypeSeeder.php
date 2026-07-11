<?php

namespace Database\Seeders;

use App\Domain\Staff\Services\AllowanceTypeProvisioningService;
use Illuminate\Database\Seeder;

class AllowanceTypeSeeder extends Seeder
{
    public function run(): void
    {
        app(AllowanceTypeProvisioningService::class)->ensureGlobal();
    }
}
