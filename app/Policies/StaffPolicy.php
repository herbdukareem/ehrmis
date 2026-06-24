<?php

namespace App\Policies;

use App\Domain\Staff\Models\Staff;
use App\Models\User;
use App\Policies\Concerns\MdaScopedPolicy;

class StaffPolicy
{
    use MdaScopedPolicy;

    public function viewAny(User $user): bool
    {
        return $user->can('view-staff');
    }

    public function view(User $user, Staff $staff): bool
    {
        return $user->can('view-staff') && $this->canAccessMda($user, $staff->mda_id);
    }

    public function create(User $user): bool
    {
        return $user->can('create-staff');
    }

    public function update(User $user, Staff $staff): bool
    {
        return $user->can('update-staff') && $this->canAccessMda($user, $staff->mda_id);
    }

    public function updateAppointment(User $user, Staff $staff): bool
    {
        return $user->can('update-staff-appointment') && $this->canAccessMda($user, $staff->mda_id);
    }

    public function updateAllowances(User $user, Staff $staff): bool
    {
        return $user->can('update-staff-allowances') && $this->canAccessMda($user, $staff->mda_id);
    }

    public function delete(User $user, Staff $staff): bool
    {
        return $user->can('delete-staff') && $this->canAccessMda($user, $staff->mda_id);
    }
}
