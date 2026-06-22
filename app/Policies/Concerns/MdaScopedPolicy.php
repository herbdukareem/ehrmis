<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait MdaScopedPolicy
{
    protected function canAccessMda(User $user, ?int $mdaId): bool
    {
        return $user->canAccessMda($mdaId);
    }
}
