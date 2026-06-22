<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\AccessManagementRules;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage-users') || $user->can('manage-roles');
    }

    public function create(User $user): bool
    {
        return AccessManagementRules::canManageAllRoles($user)
            || AccessManagementRules::canManageGlobalRoles($user)
            || AccessManagementRules::canManageOwnMdaRoles($user);
    }

    public function update(User $user, Role $role): bool
    {
        return AccessManagementRules::roleCanBeManagedBy($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        return AccessManagementRules::roleCanBeManagedBy($user, $role);
    }
}
