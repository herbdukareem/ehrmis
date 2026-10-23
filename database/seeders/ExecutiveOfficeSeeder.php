<?php

namespace Database\Seeders;

use App\Domain\ExecutiveOffice\OfficeCatalog;
use App\Domain\Module\Models\Module;
use App\Domain\Module\Models\ModuleRoleTemplate;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class ExecutiveOfficeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (OfficeCatalog::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $module = Module::query()->updateOrCreate(['code' => 'executive_office'], [
            'name' => 'Executive Office', 'description' => 'Executive diary, correspondence, decisions, meetings, visitors, and follow-up.',
            'category' => 'Operations', 'icon' => 'building', 'status' => 'active', 'sort_order' => 65,
        ]);
        foreach (OfficeCatalog::PERMISSIONS as $index => $permission) {
            $module->permissions()->updateOrCreate(['permission_name' => $permission], ['sort_order' => $index * 10]);
        }
        foreach (array_keys(OfficeCatalog::ROLES) as $index => $role) {
            $template = ModuleRoleTemplate::query()->updateOrCreate(['code' => 'executive_office_'.$role], [
                'module_id' => $module->id, 'name' => 'Executive Office '.ucwords(str_replace('_', ' ', $role)),
                'description' => 'Requires a current office assignment; administrator access does not include confidential records.',
                'scope_type' => 'mda', 'status' => 'active', 'sort_order' => $index * 10,
            ]);
            $permissions = $role === 'administrator' ? OfficeCatalog::PERMISSIONS : ['view-executive-office'];
            $template->permissions()->whereNotIn('permission_name', $permissions)->delete();
            foreach ($permissions as $permission) {
                $template->permissions()->firstOrCreate(['permission_name' => $permission]);
            }
        }
        foreach (['Super Admin', 'Platform Admin', 'MIS Admin', 'MDA Admin'] as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            $role?->givePermissionTo(OfficeCatalog::PERMISSIONS);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Activation and office memberships are explicit per MDA; seeding never grants office-record access.
    }
}
