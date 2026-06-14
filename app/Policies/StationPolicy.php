<?php

namespace App\Policies;

use App\Domain\Organization\Models\Station;
use App\Models\User;
use App\Policies\Concerns\MdaScopedPolicy;

class StationPolicy
{
    use MdaScopedPolicy;

    public function viewAny(User $user): bool
    {
        return $user->can('view-departments');
    }

    public function view(User $user, Station $station): bool
    {
        return $user->can('view-departments') && $this->canAccessMda($user, $station->mda_id);
    }
}
