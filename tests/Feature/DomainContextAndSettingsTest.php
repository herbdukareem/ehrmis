<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\MdaSetting;
use App\Domain\Organization\Models\PlatformSetting;
use App\Models\User;
use Database\Seeders\MdaUserSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainContextAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mda_domain_exposes_custom_public_context_and_rejects_another_mda_user(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $mda = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $other = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        MdaSetting::query()->create(['mda_id' => $mda->id, 'acronym' => 'MOH', 'domain' => 'moh.example.test']);
        PlatformSetting::query()->create(['state_code' => 'NG-NI', 'state_name' => 'Niger State', 'platform_name' => 'eHRMIS', 'platform_acronym' => 'eHRMIS']);
        $user = User::factory()->mdaUser($other)->create();

        $this->getJson('http://moh.example.test/api/public-context')
            ->assertOk()
            ->assertJsonPath('data.name', 'Ministry of Health')
            ->assertJsonPath('data.scope', 'mda');

        $this->postJson('http://moh.example.test/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden();
    }

    public function test_mda_user_seeder_creates_one_administrator_per_mda(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);

        $this->seed(MdaUserSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'moh@ehrmis.local', 'user_type' => 'mda_admin']);
        $this->assertDatabaseHas('users', ['email' => 'hmb@ehrmis.local', 'user_type' => 'mda_admin']);
        $this->assertDatabaseCount('user_access_scopes', 2);
    }
}
