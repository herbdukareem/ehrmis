<?php

namespace Database\Seeders;

use App\Domain\Staff\Services\PromotionPolicyCatalogSyncService;
use Illuminate\Database\Seeder;

class PromotionPolicyCatalogSeeder extends Seeder
{
    public function run(): void
    {
        app(PromotionPolicyCatalogSyncService::class)->syncAll();
    }
}
