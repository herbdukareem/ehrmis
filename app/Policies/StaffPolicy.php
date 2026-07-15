<?php

namespace App\Policies;

use App\Domain\Staff\Models\Staff;
use App\Models\User;

class StaffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-staff');
    }

    public function view(User $user, Staff $staff): bool
    {
        return $user->can('view-staff') && $user->canAccessStaff($staff);
    }

    public function create(User $user): bool
    {
        return $user->can('create-staff');
    }

    public function update(User $user, Staff $staff): bool
    {
        return $user->can('update-staff') && $user->canAccessStaff($staff);
    }

    public function updateAppointment(User $user, Staff $staff): bool
    {
        return $user->can('update-staff-appointment') && $user->canAccessStaff($staff);
    }

    public function updateAllowances(User $user, Staff $staff): bool
    {
        return $user->can('update-staff-allowances') && $user->canAccessStaff($staff);
    }

    public function delete(User $user, Staff $staff): bool
    {
        return $user->can('delete-staff') && $user->canAccessStaff($staff);
    }
}
