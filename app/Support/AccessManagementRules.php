<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AccessManagementRules
{
    /**
     * @return list<string>
     */
    public static function mdaRolePermissionNames(): array
    {
        return [
            'view-staff',
            'create-staff',
            'update-staff',
            'update-staff-appointment',
            'update-staff-allowances',
            'delete-staff',
            'import-staff',
            'view-staff-imports',
            'review-staff-imports',
            'resolve-staff-import-issues',
            'publish-own-mda-staff-imports',
            'view-movement-sheets',
            'create-movement-sheets',
            'view-promotions',
            'submit-promotion-applications',
            'screen-promotions',
            'manage-promotion-sittings',
            'decide-promotions',
            'approve-promotion-printing',
            'print-promotion-letters',
            'view-postings',
            'create-postings',
            'approve-own-mda-postings',
            'approve-receiving-mda-postings',
            'approve-inter-mda-postings',
            'print-posting-letters',
            'effect-postings',
            'view-budgets',
            'create-budgets',
            'view-reports',
            'export-reports',
            'manage-departments',
            'manage-stations',
            'manage-cadres',
            'manage-ranks',
            'manage-allowance-types',
            'manage-salary-scales',
            'manage-qualification-types',
            'manage-salary-structure',
            'manage-mda-settings',
        ];
    }

    /**
     * @return list<string>
     */
    public static function globalRoleNames(): array
    {
        return [
            'Super Admin',
            'Platform Admin',
            'MIS Admin',
            'MDA Admin',
            'HR Officer',
            'Budget Officer',
            'Payroll Auditor',
            'Report Viewer',
            'Approval Officer',
        ];
    }

    public static function canManageAccessScopes(User $user): bool
    {
        return $user->can('manage-users')
            && $user->hasPlatformAccess()
            && $user->hasAnyRole(['Super Admin', 'Platform Admin', 'MIS Admin']);
    }

    public static function canManageGlobalRoles(User $user): bool
    {
        return $user->can('manage-roles')
            && $user->hasPlatformAccess()
            && $user->hasAnyRole(['Super Admin', 'Platform Admin', 'MIS Admin']);
    }

    public static function canManageAllRoles(User $user): bool
    {
        return $user->can('manage-roles')
            && $user->hasPlatformAccess()
            && $user->hasAnyRole(['Super Admin', 'MIS Admin']);
    }

    public static function canManageOwnMdaRoles(User $user): bool
    {
        return $user->can('manage-roles')
            && $user->hasRole('MDA Admin')
            && $user->mda_id !== null;
    }

    public static function canManageUsersInOwnMda(User $user): bool
    {
        return $user->can('manage-users')
            && $user->can('create-users')
            && $user->can('update-users')
            && $user->hasRole('MDA Admin')
            && $user->mda_id !== null;
    }

    public static function canCreateUser(User $user): bool
    {
        if (self::canManageAccessScopes($user)) {
            return $user->can('create-users');
        }

        return self::canManageUsersInOwnMda($user);
    }

    public static function canManageUser(User $actor, User $managedUser): bool
    {
        if (self::canManageAccessScopes($actor)) {
            return true;
        }

        if (! self::canManageUsersInOwnMda($actor)) {
            return false;
        }

        if ((int) $managedUser->mda_id !== (int) $actor->mda_id) {
            return false;
        }

        if ($managedUser->hasGlobalMdaAccess()) {
            return false;
        }

        return $managedUser->accessibleMdaIds()->diff([(int) $actor->mda_id])->isEmpty();
    }

    public static function roleCanBeManagedBy(User $user, Role $role): bool
    {
        if (self::canManageAllRoles($user)) {
            return true;
        }

        if ($role->scope === Role::SCOPE_GLOBAL) {
            return self::canManageGlobalRoles($user);
        }

        return self::canManageOwnMdaRoles($user)
            && (int) $role->mda_id === (int) $user->mda_id;
    }

    public static function visibleRolesQuery(User $user): Builder
    {
        $query = Role::query();

        if (self::canManageAllRoles($user) || self::canManageGlobalRoles($user)) {
            return $query;
        }

        if (self::canManageOwnMdaRoles($user)) {
            return $query
                ->where('scope', Role::SCOPE_MDA)
                ->where('mda_id', $user->mda_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function manageableUsersQuery(User $user): Builder
    {
        if (self::canManageAccessScopes($user)) {
            return User::query()->with(['mda', 'roles.mda', 'accessScopes.mda'])->orderBy('name');
        }

        if (self::canManageUsersInOwnMda($user)) {
            return User::query()
                ->where('mda_id', $user->mda_id)
                ->whereDoesntHave('accessScopes', function (Builder $query) use ($user): void {
                    $query->where(function (Builder $scopeQuery) use ($user): void {
                        $scopeQuery
                            ->whereIn('scope_type', ['platform', 'state'])
                            ->orWhere(function (Builder $mdaScopeQuery) use ($user): void {
                                $mdaScopeQuery
                                    ->where('scope_type', 'mda')
                                    ->where('mda_id', '!=', $user->mda_id);
                            });
                    });
                })
                ->with(['mda', 'roles.mda', 'accessScopes.mda'])
                ->orderBy('name');
        }

        return User::query()->whereRaw('1 = 0');
    }

    public static function mdaRoleNameReserved(string $name): bool
    {
        return in_array($name, self::globalRoleNames(), true);
    }

    public static function canManageUserStatus(User $actor): bool
    {
        return $actor->can('deactivate-users');
    }
}
