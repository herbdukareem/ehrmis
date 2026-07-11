<?php

namespace App\Support;

use App\Models\User;

class SetupManagementRules
{
    /**
     * @return array<string, string>
     */
    public static function decisions(): array
    {
        return [
            'mdas' => 'platform-owned',
            'departments' => 'mda-owned',
            'stations' => 'mda-owned',
            'cadres' => 'mda-owned-via-department',
            'ranks' => 'mda-owned-via-cadre',
            'allowance-types' => 'unified-global',
            'salary-scales' => 'unified-global',
            'qualification-types' => 'unified-global',
            'promotion-policies' => 'unified-global',
            'salary-structure-rates' => 'unified-global',
            'salary-structure-rate-allowances' => 'unified-global',
        ];
    }

    /**
     * @return list<string>
     */
    public static function managementPermissions(): array
    {
        return [
            'manage-mdas',
            'manage-departments',
            'manage-stations',
            'manage-cadres',
            'manage-ranks',
            'manage-allowance-types',
            'manage-salary-scales',
            'manage-qualification-types',
            'manage-promotion-policies',
            'manage-salary-structure',
        ];
    }

    public static function canViewPage(User $user): bool
    {
        foreach (self::managementPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function canManageGlobalSetup(User $user, string $permission): bool
    {
        return $user->can($permission)
            && $user->hasPlatformAccess()
            && $user->hasAnyRole(['Super Admin', 'Platform Admin', 'MIS Admin']);
    }

    public static function canManageMdaOwnedSetup(User $user, string $permission, ?int $mdaId = null): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        if ($mdaId === null) {
            return $user->hasAnyMdaAccess();
        }

        return $user->canAccessMda($mdaId);
    }
}
