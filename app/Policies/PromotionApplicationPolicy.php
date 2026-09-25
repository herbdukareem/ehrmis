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
        return $user->can('view-promotions')
            && $this->canAccessMda($user, $application->mda_id)
            && $this->canAccessApplicationDepartment($user, $application);
    }

    public function screen(User $user, PromotionApplication $application): bool
    {
        return $user->can('screen-promotions')
            && $this->canAccessMda($user, $application->mda_id)
            && $this->canAccessApplicationDepartment($user, $application);
    }

    public function decide(User $user, PromotionApplication $application): bool
    {
        return $user->can('decide-promotions')
            && $this->canAccessMda($user, $application->mda_id)
            && $this->canAccessApplicationDepartment($user, $application);
    }

    public function print(User $user, PromotionApplication $application): bool
    {
        return $user->can('print-promotion-letters')
            && $this->canAccessMda($user, $application->mda_id)
            && $this->canAccessApplicationDepartment($user, $application);
    }

    protected function canAccessApplicationDepartment(User $user, PromotionApplication $application): bool
    {
        if (! $user->hasDepartmentRestrictedAccess()) {
            return true;
        }

        $application->loadMissing('staff.currentEmployment');

        if ($application->staff) {
            return $user->canAccessStaff($application->staff);
        }

        $departmentId = data_get($application->current_snapshot, 'department.id');

        return $departmentId !== null && $user->canAccessDepartment((int) $departmentId);
    }
}
