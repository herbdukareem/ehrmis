<?php

namespace Database\Seeders;

use App\Domain\Staff\Services\QualificationCatalogSyncService;
use Illuminate\Database\Seeder;

class QualificationCatalogSeeder extends Seeder
{
    public function run(): void
    {
        app(QualificationCatalogSyncService::class)->syncAll();
    }
}
