<?php

namespace App\Policies;

use App\Domain\Organization\Models\Location;
use App\Models\User;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-departments');
    }

    public function view(User $user, Location $location): bool
    {
        return $user->can('view-departments');
    }
}
