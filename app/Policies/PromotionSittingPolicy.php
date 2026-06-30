<?php

namespace App\Policies;

use App\Domain\Promotion\Models\PromotionSitting;
use App\Models\User;
use App\Policies\Concerns\MdaScopedPolicy;

class PromotionSittingPolicy
{
    use MdaScopedPolicy;

    public function view(User $user, PromotionSitting $sitting): bool
    {
        return $user->can('view-promotions') && $this->canAccessMda($user, $sitting->mda_id);
    }

    public function manage(User $user, PromotionSitting $sitting): bool
    {
        return $user->can('manage-promotion-sittings') && $this->canAccessMda($user, $sitting->mda_id);
    }

    public function decide(User $user, PromotionSitting $sitting): bool
    {
        return $user->can('decide-promotions') && $this->canAccessMda($user, $sitting->mda_id);
    }

    public function approvePrint(User $user, PromotionSitting $sitting): bool
    {
        return $user->can('approve-promotion-printing') && $this->canAccessMda($user, $sitting->mda_id);
    }
}
