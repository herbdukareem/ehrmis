<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            OrganizationSeeder::class,
            ModuleSeeder::class,
            ServiceReportingSeeder::class,
            AllowanceTypeSeeder::class,
            SalaryScaleSeeder::class,
            QualificationCatalogSeeder::class,
            PromotionPolicyCatalogSeeder::class,
            SuperAdminSeeder::class,
            PlatformAndMdaSettingsSeeder::class,
            MdaUserSeeder::class,
        ]);
    }
}
