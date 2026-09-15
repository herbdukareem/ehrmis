<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_station_location_fields_can_be_created_updated_cleared_and_read_from_setup(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $mda = Mda::factory()->create();
        $user = User::factory()->mdaUser($mda)->create();
        $user->assignRole('MDA Admin');
        $default = Station::factory()->create(['mda_id' => $mda->id])->fresh();
        $this->assertFalse($default->is_rural);
        $this->assertNull($default->lga);
        $this->actingAs($user);
        $payload = ['code' => 'TEST', 'name' => 'Station Location Test', 'status' => 'active'];
        $response = $this->postJson('/api/setup-management/stations', $payload + ['lga' => '  Chanchaga  ', 'is_rural' => 1])
            ->assertCreated()->assertJsonPath('data.lga', 'CHANCHAGA')->assertJsonPath('data.is_rural', true);
        $id = $response->json('data.id');
        $this->putJson('/api/setup-management/stations/'.$id, $payload)
            ->assertOk()->assertJsonPath('data.lga', 'CHANCHAGA')->assertJsonPath('data.is_rural', true);
        $setup = $this->getJson('/api/setup-management')->assertOk();
        $station = collect($setup->json('data.stations'))->firstWhere('id', $id);
        $this->assertSame('CHANCHAGA', $station['lga']);
        $this->assertTrue($station['is_rural']);
        $list = $this->getJson('/api/stations')->assertOk();
        $this->assertSame('CHANCHAGA', collect($list->json('data'))->firstWhere('id', $id)['lga']);
        $this->putJson('/api/setup-management/stations/'.$id, $payload + ['lga' => null, 'is_rural' => 0])
            ->assertOk()->assertJsonPath('data.lga', null)->assertJsonPath('data.is_rural', false);
        $this->postJson('/api/setup-management/stations', ['code' => 'DEFAULT', 'name' => 'Default station', 'status' => 'active'])
            ->assertCreated()->assertJsonPath('data.is_rural', false);
    }

    public function test_station_location_updates_validate_input_and_remain_mda_scoped(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $mda = Mda::factory()->create();
        $other = Station::factory()->create(['lga' => 'PRIVATE LGA']);
        $user = User::factory()->mdaUser($mda)->create();
        $user->assignRole('MDA Admin');
        $this->actingAs($user);
        $payload = ['code' => 'GEO', 'name' => 'Geo station', 'status' => 'active'];
        $this->postJson('/api/setup-management/stations', $payload + ['lga' => str_repeat('a', 121), 'is_rural' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors(['lga', 'is_rural']);
        $this->putJson('/api/setup-management/stations/'.$other->id, $payload + ['lga' => 'CHANCHAGA', 'is_rural' => true])->assertNotFound();
        $this->getJson('/api/stations?mda_id='.$other->mda_id)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/setup-management/stations', $payload + ['mda_id' => $other->mda_id, 'lga' => 'CHANCHAGA'])->assertForbidden();
    }

    public function test_station_lga_backfill_matches_supplied_names_and_aliases_without_overwriting_manual_values_or_other_mdas(): void
    {
        $mda = Mda::factory()->create(['code' => 'HMB']);
        $foreign = Station::factory()->create(['name' => 'GH AUNA']);
        $expected = ['GH AUNA' => 'MAGAMA', 'GH MI WUSHISHI' => 'CHANCHAGA', 'GH WUSHISHI' => 'WUSHISHI', 'JBAM&NH' => 'CHANCHAGA', 'GH TALBA ESTATE' => 'CHANCHAGA', 'REHAB.' => 'CHANCHAGA'];
        foreach ($expected as $name => $lga) {
            Station::factory()->create(['mda_id' => $mda->id, 'name' => $name, 'is_rural' => true]);
        }
        $manual = Station::factory()->create(['mda_id' => $mda->id, 'name' => 'GH MINNA', 'lga' => 'MANUALLY VERIFIED', 'is_rural' => true]);
        $unknown = Station::factory()->create(['mda_id' => $mda->id, 'name' => 'UNLISTED STATION']);
        $this->artisan('stations:backfill-lga', ['mda' => 'HMB', '--dry-run' => true])->assertSuccessful();
        $this->assertSame(1, Station::query()->whereNotNull('lga')->count());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->artisan('stations:backfill-lga', ['mda' => 'HMB'])->assertSuccessful();
        foreach ($expected as $name => $lga) {
            $this->assertDatabaseHas('stations', ['mda_id' => $mda->id, 'name' => $name, 'lga' => $lga, 'is_rural' => true]);
        }
        $this->assertSame('MANUALLY VERIFIED', $manual->fresh()->lga);
        $this->assertTrue($manual->fresh()->is_rural);
        $this->assertNull($unknown->fresh()->lga);
        $this->assertNull($foreign->fresh()->lga);
        $this->assertDatabaseCount('audit_logs', count($expected));
        $this->artisan('stations:backfill-lga', ['mda' => $mda->id])->assertSuccessful();
        $this->assertDatabaseCount('audit_logs', count($expected));
        $this->assertDatabaseCount('stations', count($expected) + 3);
    }
}
