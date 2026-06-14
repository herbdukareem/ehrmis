<?php

namespace Database\Seeders;

use App\Domain\Organization\Models\Mda;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MdaUserSeeder extends Seeder
{
    public function run(): void
    {
        Mda::query()->each(function (Mda $mda): void {
            $email = Str::slug(strtolower($mda->code)).'@ehrmis.local';
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'mda_id' => $mda->id,
                    'name' => $mda->code.' Administrator',
                    'password' => Hash::make('password'),
                    'user_type' => UserType::MDA_ADMIN,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ],
            );
            $user->syncRoles(['MDA Admin']);
            $user->accessScopes()->updateOrCreate(
                ['scope_type' => 'mda', 'mda_id' => $mda->id],
                ['state_code' => 'NG-NI'],
            );
        });
    }
}
