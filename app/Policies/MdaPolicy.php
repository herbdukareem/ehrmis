<?php

namespace App\Policies;

use App\Domain\Organization\Models\Mda;
use App\Models\User;

class MdaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-mdas');
    }

    public function view(User $user, Mda $mda): bool
    {
        return $user->can('view-mdas') && $user->canAccessMda($mda->id);
    }
}
