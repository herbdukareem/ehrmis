<?php

namespace Database\Seeders;

use App\Domain\Module\Models\MdaModule;
use App\Domain\Module\Models\Module;
use App\Domain\Module\Models\ModuleRoleTemplate;
use App\Domain\Organization\Models\Mda;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class BudgetProcurementAccessSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $definition = ModuleSeeder::MODULES['budget_procurement'];
            $permissions = $definition['permissions'];
            $module = Module::query()->firstOrCreate(['code' => 'budget_procurement'], [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'category' => $definition['category'],
                'icon' => $definition['icon'],
                'status' => 'active',
                'sort_order' => 70,
            ]);

            foreach ($permissions as $index => $name) {
                Permission::findOrCreate($name, 'web');
                $module->permissions()->firstOrCreate(['permission_name' => $name], ['sort_order' => ($index + 1) * 10]);
            }

            $templates = [
                'viewer' => $definition['templates']['viewer'],
                'officer' => $definition['templates']['officer'],
                'admin' => $permissions,
            ];
            foreach ($templates as $code => $names) {
                $template = ModuleRoleTemplate::query()->firstOrCreate(['code' => 'budget_procurement_'.$code], [
                    'module_id' => $module->id,
                    'name' => $definition['name'].' '.ucfirst($code),
                    'scope_type' => 'mda',
                    'status' => 'active',
                    'sort_order' => match ($code) {
                        'viewer' => 10, 'officer' => 20, default => 30
                    },
                ]);
                if ($template->wasRecentlyCreated) {
                    $template->permissions()->createMany(array_map(fn (string $name): array => ['permission_name' => $name], $names));
                }
            }

            // This is a default module, but an explicit disabled assignment must remain disabled.
            Mda::query()->each(function (Mda $mda) use ($module): void {
                MdaModule::query()->firstOrCreate(['mda_id' => $mda->id, 'module_id' => $module->id], [
                    'enabled' => true, 'enabled_at' => now(),
                ]);
            });

            $rolePermissions = [
                'Super Admin' => $permissions,
                'Platform Admin' => $permissions,
                'MIS Admin' => $permissions,
                'MDA Admin' => $permissions,
                'Budget Officer' => $templates['officer'],
                'Payroll Auditor' => [...$templates['viewer'], 'export-procurement-reports'],
                'Report Viewer' => [...$templates['viewer'], 'export-procurement-reports'],
                'Approval Officer' => ['view-budget-cycles', 'approve-budget-cycles', 'view-procurement', 'approve-requisitions', 'evaluate-bids', 'approve-awards', 'view-supplier-payables', 'approve-supplier-invoices', 'approve-supplier-payments'],
            ];
            foreach ($rolePermissions as $name => $names) {
                $role = Role::query()->where('name', $name)->where('guard_name', 'web')->global()->whereNull('mda_id')->first();
                $role?->givePermissionTo($names);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
