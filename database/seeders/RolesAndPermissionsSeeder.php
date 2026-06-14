<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'view-mdas',
            'create-mdas',
            'update-mdas',
            'delete-mdas',
            'view-departments',
            'create-departments',
            'update-departments',
            'delete-departments',
            'view-staff',
            'create-staff',
            'update-staff',
            'delete-staff',
            'import-staff',
            'approve-staff-imports',
            'view-staff-imports',
            'review-staff-imports',
            'resolve-staff-import-issues',
            'publish-staff-imports',
            'publish-own-mda-staff-imports',
            'view-movement-sheets',
            'create-movement-sheets',
            'approve-movement-sheets',
            'view-budgets',
            'create-budgets',
            'approve-budgets',
            'view-reports',
            'export-reports',
            'view-audit-logs',
            'manage-settings',
            'manage-platform-settings',
            'manage-mda-settings',
            'manage-roles',
            'manage-users',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $roleMap = [
            'Super Admin' => $permissions,
            'MIS Admin' => array_values(array_diff($permissions, ['manage-settings'])),
            'MDA Admin' => [
                'view-departments',
                'create-departments',
                'update-departments',
                'view-staff',
                'create-staff',
                'update-staff',
                'delete-staff',
                'import-staff',
                'view-staff-imports',
                'review-staff-imports',
                'resolve-staff-import-issues',
                'publish-own-mda-staff-imports',
                'view-movement-sheets',
                'view-budgets',
                'view-reports',
                'manage-users',
                'manage-mda-settings',
            ],
            'HR Officer' => [
                'view-staff',
                'create-staff',
                'update-staff',
                'delete-staff',
                'import-staff',
                'view-staff-imports',
                'review-staff-imports',
                'view-reports',
            ],
            'Budget Officer' => [
                'view-movement-sheets',
                'create-movement-sheets',
                'view-budgets',
                'create-budgets',
                'view-reports',
            ],
            'Payroll Auditor' => [
                'view-staff',
                'view-budgets',
                'view-reports',
                'export-reports',
            ],
            'Report Viewer' => [
                'view-reports',
                'export-reports',
            ],
            'Approval Officer' => [
                'approve-staff-imports',
                'view-staff-imports',
                'review-staff-imports',
                'publish-staff-imports',
                'approve-movement-sheets',
                'approve-budgets',
                'view-reports',
            ],
        ];

        foreach ($roleMap as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($rolePermissions);
        }
    }
}
