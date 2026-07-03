<?php

namespace Database\Seeders;

use App\Domain\Module\Models\MdaModule;
use App\Domain\Module\Models\Module;
use App\Domain\Module\Models\ModulePermission;
use App\Domain\Module\Models\ModuleRoleTemplate;
use App\Domain\Organization\Models\Mda;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class ModuleSeeder extends Seeder
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public const MODULES = [
        'staff_registry' => [
            'name' => 'Staff Registry',
            'description' => 'Core staff records, appointments, documents, postings, and promotions.',
            'category' => 'Operations',
            'icon' => 'users',
            'permissions' => [
                'view-staff',
                'create-staff',
                'update-staff',
                'update-staff-appointment',
                'update-staff-allowances',
                'delete-staff',
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
            ],
        ],
        'legacy_import' => [
            'name' => 'Legacy Staff Import',
            'description' => 'Import, review, resolve, approve, and publish legacy staff records.',
            'category' => 'Operations',
            'icon' => 'upload',
            'permissions' => [
                'import-staff',
                'approve-staff-imports',
                'view-staff-imports',
                'review-staff-imports',
                'resolve-staff-import-issues',
                'publish-staff-imports',
                'publish-own-mda-staff-imports',
            ],
        ],
        'movement_budget' => [
            'name' => 'Movement & Budget Workbooks',
            'description' => 'Movement sheets, budget workbooks, approvals, and exports.',
            'category' => 'Planning',
            'icon' => 'file-spreadsheet',
            'permissions' => [
                'view-movement-sheets',
                'create-movement-sheets',
                'approve-movement-sheets',
                'view-budgets',
                'create-budgets',
                'approve-budgets',
            ],
        ],
        'service_reporting' => [
            'name' => 'MDA Service Reporting & Returns',
            'description' => 'MDA service returns, review, approval, locking, export, and template assignment.',
            'category' => 'Reporting',
            'icon' => 'clipboard-list',
            'permissions' => [
                'view-service-reports',
                'create-service-reports',
                'submit-service-reports',
                'review-service-reports',
                'return-service-reports',
                'approve-service-reports',
                'lock-service-reports',
                'export-service-reports',
                'manage-report-templates',
                'assign-report-templates',
            ],
        ],
        'dashboards_analytics' => [
            'name' => 'Dashboards & Analytics',
            'description' => 'Executive dashboards, reporting, and exportable analytics.',
            'category' => 'Intelligence',
            'icon' => 'bar-chart',
            'permissions' => [
                'view-reports',
                'export-reports',
            ],
        ],
        'settings' => [
            'name' => 'Settings',
            'description' => 'Platform, MDA, and setup configuration.',
            'category' => 'Administration',
            'icon' => 'settings',
            'permissions' => [
                'manage-settings',
                'manage-platform-settings',
                'manage-mda-settings',
                'view-mdas',
                'create-mdas',
                'update-mdas',
                'delete-mdas',
                'view-departments',
                'create-departments',
                'update-departments',
                'delete-departments',
                'manage-departments',
                'manage-stations',
                'manage-cadres',
                'manage-ranks',
                'manage-allowance-types',
                'manage-salary-scales',
                'manage-qualification-types',
                'manage-salary-structure',
            ],
        ],
        'access_management' => [
            'name' => 'Access Management',
            'description' => 'Users, roles, role templates, scopes, and MDA module access.',
            'category' => 'Administration',
            'icon' => 'shield',
            'permissions' => [
                'manage-users',
                'manage-roles',
                'create-users',
                'update-users',
                'deactivate-users',
            ],
        ],
        'audit_logs' => [
            'name' => 'Audit Logs',
            'description' => 'Security and workflow audit trails.',
            'category' => 'Administration',
            'icon' => 'history',
            'permissions' => [
                'view-audit-logs',
            ],
        ],
    ];

    public function run(): void
    {
        $sort = 10;

        foreach (self::MODULES as $code => $definition) {
            $module = Module::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'category' => $definition['category'],
                    'icon' => $definition['icon'],
                    'status' => 'active',
                    'sort_order' => $sort,
                ],
            );

            foreach ($definition['permissions'] as $index => $permissionName) {
                Permission::findOrCreate($permissionName, 'web');
                ModulePermission::query()->updateOrCreate(
                    ['module_id' => $module->id, 'permission_name' => $permissionName],
                    ['sort_order' => ($index + 1) * 10],
                );
            }

            $this->seedTemplates($module, $definition['permissions']);
            $sort += 10;
        }

        $this->enableDefaultsForExistingMdas();
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function seedTemplates(Module $module, array $permissions): void
    {
        $viewPermission = collect($permissions)->first(fn (string $permission): bool => str_starts_with($permission, 'view-'));
        $base = $viewPermission ? [$viewPermission] : [];

        $templates = [
            [
                'name' => $module->name.' Viewer',
                'code' => $module->code.'_viewer',
                'description' => 'Read-only access for '.$module->name.'.',
                'permissions' => $base,
                'sort_order' => 10,
            ],
            [
                'name' => $module->name.' Officer',
                'code' => $module->code.'_officer',
                'description' => 'Operational access for '.$module->name.'.',
                'permissions' => collect($permissions)
                    ->filter(fn (string $permission): bool => str_starts_with($permission, 'view-') || str_starts_with($permission, 'create-') || str_starts_with($permission, 'submit-') || str_starts_with($permission, 'import-'))
                    ->values()
                    ->all(),
                'sort_order' => 20,
            ],
            [
                'name' => $module->name.' Admin',
                'code' => $module->code.'_admin',
                'description' => 'Full module-level access for '.$module->name.'.',
                'permissions' => $permissions,
                'sort_order' => 30,
            ],
        ];

        foreach ($templates as $templateData) {
            $template = ModuleRoleTemplate::query()->updateOrCreate(
                ['code' => $templateData['code']],
                [
                    'module_id' => $module->id,
                    'name' => $templateData['name'],
                    'description' => $templateData['description'],
                    'scope_type' => 'mda',
                    'status' => 'active',
                    'sort_order' => $templateData['sort_order'],
                ],
            );

            $template->permissions()->delete();
            foreach ($templateData['permissions'] as $permissionName) {
                $template->permissions()->create(['permission_name' => $permissionName]);
            }
        }
    }

    protected function enableDefaultsForExistingMdas(): void
    {
        $defaultModules = ['staff_registry', 'legacy_import', 'movement_budget', 'dashboards_analytics', 'settings', 'access_management'];
        $hmbModules = [...$defaultModules, 'service_reporting'];

        Mda::query()->each(function (Mda $mda) use ($defaultModules, $hmbModules): void {
            $isHmb = str_contains(strtoupper($mda->code), 'HMB')
                || str_contains(strtoupper($mda->name), 'HMB')
                || str_contains(strtoupper($mda->name), 'HOSPITAL MANAGEMENT BOARD')
                || str_contains(strtoupper($mda->name), 'HOSPITALS MANAGEMENT BOARD');

            foreach ($isHmb ? $hmbModules : $defaultModules as $moduleCode) {
                $moduleId = Module::query()->where('code', $moduleCode)->value('id');

                if (! $moduleId) {
                    continue;
                }

                MdaModule::query()->updateOrCreate(
                    ['mda_id' => $mda->id, 'module_id' => $moduleId],
                    [
                        'enabled' => true,
                        'enabled_at' => now(),
                        'disabled_at' => null,
                    ],
                );
            }
        });
    }
}
