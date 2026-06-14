<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'admin@ehrmis.local'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'user_type' => UserType::SUPER_ADMIN,
                'status' => 'active',
                'mda_id' => null,
            ],
        );

        $user->syncRoles(['Super Admin']);
        $user->accessScopes()->updateOrCreate(
            ['scope_type' => 'platform', 'state_code' => 'NG-NI'],
            ['mda_id' => null],
        );
    }
}
