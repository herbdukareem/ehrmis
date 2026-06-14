<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait MdaScopedPolicy
{
    protected function canAccessMda(User $user, ?int $mdaId): bool
    {
        return $user->hasGlobalMdaAccess() || ($user->mda_id !== null && $user->mda_id === $mdaId);
    }
}
