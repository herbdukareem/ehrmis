<?php

namespace Tests\Feature;

use App\Domain\Module\Models\MdaModule;
use App\Domain\Module\Models\Module;
use App\Domain\Module\Models\ModuleRoleTemplate;
use App\Domain\Organization\Models\Mda;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BudgetProcurementAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BudgetProcurementAccessSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_installing_missing_access_makes_the_pages_available_to_existing_admins(): void
    {
        $mda = Mda::factory()->create();
        $role = Role::query()->create(['name' => 'Super Admin', 'guard_name' => 'web', 'scope' => Role::SCOPE_GLOBAL]);
        $user = User::factory()->superAdmin()->create();
        $user->assignRole($role);

        $this->assertDatabaseMissing('modules', ['code' => 'budget_procurement']);
        $this->seed(BudgetProcurementAccessSeeder::class);

        $session = $this->actingAs($user->fresh())->getJson('/api/me')->assertOk()->json('data');
        $this->assertContains('budget_procurement', $session['enabled_modules']);
        $this->assertContains('view-budget-cycles', $session['permissions']);
        $this->assertContains('view-procurement', $session['permissions']);
        $this->assertContains('view-supplier-payables', $session['permissions']);
        $this->assertContains('approve-supplier-payments', $session['permissions']);
        $this->getJson('/api/budget-procurement/options?mda_id='.$mda->id)->assertOk();
        $this->getJson('/api/budget-procurement/cycles?mda_id='.$mda->id)->assertOk();
        $this->getJson('/api/budget-procurement/plans?mda_id='.$mda->id)->assertOk();
        $this->assertDatabaseCount('module_role_templates', 3);
    }

    public function test_repair_is_additive_idempotent_and_preserves_disabled_modules_and_custom_roles(): void
    {
        $mda = Mda::factory()->create();
        $otherMda = Mda::factory()->create();
        $module = Module::query()->create(['code' => 'budget_procurement', 'name' => 'Budget', 'status' => 'active']);
        MdaModule::query()->create(['mda_id' => $mda->id, 'module_id' => $module->id, 'enabled' => false]);
        $permission = Permission::findOrCreate('existing-custom-permission', 'web');
        $role = Role::query()->create(['name' => 'Budget Officer', 'guard_name' => 'web', 'scope' => Role::SCOPE_GLOBAL]);
        $role->givePermissionTo($permission);
        $customRole = Role::query()->create(['name' => 'Custom Budget Reader', 'guard_name' => 'web', 'scope' => Role::SCOPE_MDA, 'mda_id' => $mda->id]);
        $customRole->givePermissionTo($permission);
        $template = ModuleRoleTemplate::query()->create(['module_id' => $module->id, 'name' => 'Customised viewer', 'code' => 'budget_procurement_viewer', 'scope_type' => 'mda', 'status' => 'active']);
        $template->permissions()->create(['permission_name' => 'existing-custom-permission']);

        $this->seed(BudgetProcurementAccessSeeder::class);
        $this->seed(BudgetProcurementAccessSeeder::class);

        $this->assertFalse((bool) MdaModule::query()->where('mda_id', $mda->id)->where('module_id', $module->id)->value('enabled'));
        $this->assertTrue((bool) MdaModule::query()->where('mda_id', $otherMda->id)->where('module_id', $module->id)->value('enabled'));
        $this->assertTrue($role->fresh()->hasPermissionTo('existing-custom-permission'));
        $this->assertTrue($role->fresh()->hasPermissionTo('view-procurement'));
        $this->assertFalse($role->fresh()->hasPermissionTo('approve-awards'));
        $this->assertTrue($role->fresh()->hasPermissionTo('record-supplier-payments'));
        $this->assertFalse($role->fresh()->hasPermissionTo('approve-supplier-payments'));
        $this->assertSame(['existing-custom-permission'], $customRole->fresh()->permissions->pluck('name')->all());
        $this->assertSame(['existing-custom-permission'], $template->fresh()->permissions->pluck('permission_name')->all());
        $this->assertDatabaseCount('mda_modules', 2);
        $this->assertDatabaseCount('module_role_templates', 3);
    }

    public function test_repair_does_not_reactivate_an_explicitly_inactive_module(): void
    {
        Module::query()->create(['code' => 'budget_procurement', 'name' => 'Budget', 'status' => 'inactive']);
        $this->seed(BudgetProcurementAccessSeeder::class);
        $this->assertDatabaseHas('modules', ['code' => 'budget_procurement', 'status' => 'inactive']);
    }
}
