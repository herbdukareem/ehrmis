<?php

namespace App\Policies;

use App\Domain\Promotion\Models\PromotionApplication;
use App\Models\User;
use App\Policies\Concerns\MdaScopedPolicy;

class PromotionApplicationPolicy
{
    use MdaScopedPolicy;

    public function viewAny(User $user): bool
    {
        return $user->can('view-promotions');
    }

    public function view(User $user, PromotionApplication $application): bool
    {
        return $user->can('view-promotions') && $this->canAccessMda($user, $application->mda_id);
    }

    public function screen(User $user, PromotionApplication $application): bool
    {
        return $user->can('screen-promotions') && $this->canAccessMda($user, $application->mda_id);
    }

    public function decide(User $user, PromotionApplication $application): bool
    {
        return $user->can('decide-promotions') && $this->canAccessMda($user, $application->mda_id);
    }

    public function print(User $user, PromotionApplication $application): bool
    {
        return $user->can('print-promotion-letters') && $this->canAccessMda($user, $application->mda_id);
    }
}
