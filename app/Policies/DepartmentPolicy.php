<?php

namespace App\Policies;

use App\Domain\Organization\Models\Department;
use App\Models\User;
use App\Policies\Concerns\MdaScopedPolicy;

class DepartmentPolicy
{
    use MdaScopedPolicy;

    public function viewAny(User $user): bool
    {
        return $user->can('view-departments');
    }

    public function view(User $user, Department $department): bool
    {
        return $user->can('view-departments') && $this->canAccessMda($user, $department->mda_id);
    }
}
