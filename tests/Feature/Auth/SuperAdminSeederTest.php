<?php

namespace Tests\Feature\Auth;

use App\Enums\UserType;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_seeder_repairs_existing_admin_credentials(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $mda = Mda::factory()->create();

        User::query()->create([
            'name' => 'Old Admin',
            'email' => 'admin@ehrmis.local',
            'password' => Hash::make('old-password'),
            'user_type' => UserType::MDA_ADMIN,
            'status' => 'inactive',
            'mda_id' => $mda->id,
        ]);

        $this->seed(SuperAdminSeeder::class);

        $user = User::query()->where('email', 'admin@ehrmis.local')->firstOrFail();

        $this->assertSame('Super Admin', $user->name);
        $this->assertNull($user->mda_id);
        $this->assertSame(UserType::SUPER_ADMIN, $user->user_type);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertTrue($user->hasRole('Super Admin'));
    }
}
