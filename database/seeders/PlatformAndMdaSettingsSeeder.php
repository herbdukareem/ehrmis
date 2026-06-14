<?php

namespace Database\Seeders;

use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\MdaSetting;
use App\Domain\Organization\Models\PlatformSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PlatformAndMdaSettingsSeeder extends Seeder
{
    public function run(): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['state_code' => 'NG-NI'],
            [
                'state_name' => 'Niger State',
                'platform_name' => 'Niger State eHRMIS',
                'platform_acronym' => 'eHRMIS',
                'default_domain' => parse_url(config('app.url'), PHP_URL_HOST),
                'logo_path' => 'images/niger-state-logo.jpg',
                'allow_platform_login' => true,
            ],
        );

        Mda::query()->each(function (Mda $mda): void {
            MdaSetting::query()->firstOrCreate(
                ['mda_id' => $mda->id],
                [
                    'acronym' => $mda->code,
                    'domain' => Str::slug(strtolower($mda->code)).'-ehrmis.test',
                    'email' => strtolower($mda->code).'@nigerstate.gov.ng',
                ],
            );
        });
    }
}
