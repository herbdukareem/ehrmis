<?php

namespace App\Policies;

use App\Domain\Promotion\Models\PromotionCycle;
use App\Models\User;
use App\Policies\Concerns\MdaScopedPolicy;

class PromotionCyclePolicy
{
    use MdaScopedPolicy;

    public function viewAny(User $user): bool
    {
        return $user->can('view-promotions');
    }

    public function view(User $user, PromotionCycle $cycle): bool
    {
        return $user->can('view-promotions')
            && ($cycle->mda_id === null || $this->canAccessMda($user, $cycle->mda_id));
    }

    public function create(User $user): bool
    {
        return $user->can('manage-promotion-sittings');
    }
}
