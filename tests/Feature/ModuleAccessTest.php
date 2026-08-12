<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Module\Models\MdaModule;
use App\Domain\Module\Models\Module;
use App\Domain\Organization\Models\Mda;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_enable_and_disable_module_for_mda(): void
    {
        [$moh] = $this->seedModuleFixtures();
        $platformAdmin = $this->makePlatformAdmin();

        $this->actingAs($platformAdmin)
            ->putJson(route('api.mdas.modules.update', $moh), [
                'modules' => [
                    ['code' => 'service_reporting', 'enabled' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'MDA module access updated.');

        $this->assertTrue($this->mdaHasModule($moh, 'service_reporting'));
        $this->assertTrue(AuditLog::query()->where('event_code', 'module_access.enabled')->exists());

        $this->actingAs($platformAdmin)
            ->putJson(route('api.mdas.modules.update', $moh), [
                'modules' => [
                    ['code' => 'service_reporting', 'enabled' => false],
                ],
            ])
            ->assertOk();

        $this->assertFalse($this->mdaHasModule($moh, 'service_reporting'));
        $this->assertTrue(AuditLog::query()->where('event_code', 'module_access.disabled')->exists());
    }

    public function test_mda_admin_cannot_enable_or_disable_modules(): void
    {
        [$moh] = $this->seedModuleFixtures();
        $mdaAdmin = $this->makeMdaAdmin($moh);

        $this->actingAs($mdaAdmin)
            ->putJson(route('api.mdas.modules.update', $moh), [
                'modules' => [
                    ['code' => 'service_reporting', 'enabled' => true],
                ],
            ])
            ->assertForbidden();
    }

    public function test_service_report_access_requires_enabled_module_and_permission(): void
    {
        [$moh, $hmb] = $this->seedModuleFixtures();

        $permittedUser = User::factory()->mdaUser($hmb)->create();
        $permittedUser->assignRole('MDA Admin');
        $permittedUser->accessScopes()->create(['scope_type' => 'mda', 'state_code' => null, 'mda_id' => $hmb->id]);

        $this->actingAs($permittedUser)
            ->getJson(route('api.service-reports.index', ['mda_id' => $hmb->id]))
            ->assertOk()
            ->assertJsonStructure(['data' => ['summary', 'templates', 'pending_submissions', 'compliance']]);

        $this->actingAs($permittedUser)
            ->getJson(route('api.service-reports.index', ['mda_id' => $moh->id]))
            ->assertForbidden();

        $module = Module::query()->where('code', 'service_reporting')->firstOrFail();
        MdaModule::query()->updateOrCreate(
            ['mda_id' => $hmb->id, 'module_id' => $module->id],
            ['enabled' => false, 'disabled_at' => now()]
        );

        $this->actingAs($permittedUser)
            ->getJson(route('api.service-reports.index', ['mda_id' => $hmb->id]))
            ->assertForbidden();

        MdaModule::query()->where('mda_id', $hmb->id)->where('module_id', $module->id)->update([
            'enabled' => true,
            'enabled_at' => now(),
            'disabled_at' => null,
        ]);

        $missingPermissionUser = User::factory()->mdaUser($hmb)->create();
        $missingPermissionUser->accessScopes()->create(['scope_type' => 'mda', 'state_code' => null, 'mda_id' => $hmb->id]);

        $this->actingAs($missingPermissionUser)
            ->getJson(route('api.service-reports.index', ['mda_id' => $hmb->id]))
            ->assertForbidden();
    }

    public function test_me_includes_enabled_modules(): void
    {
        [, $hmb] = $this->seedModuleFixtures();
        $user = User::factory()->mdaUser($hmb)->create();
        $user->assignRole('MDA Admin');
        $user->accessScopes()->create(['scope_type' => 'mda', 'state_code' => null, 'mda_id' => $hmb->id]);

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.enabled_modules.0', 'staff_registry')
            ->assertJsonFragment(['code' => 'service_reporting']);
    }

    public function test_me_exposes_workplan_module_and_permissions_when_enabled(): void
    {
        [$moh] = $this->seedModuleFixtures();
        $user = $this->makeMdaAdmin($moh);

        $response = $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonFragment(['code' => 'workplan_performance']);

        $this->assertContains('workplan_performance', $response->json('data.enabled_modules'));
        $this->assertContains('view-workplans', $response->json('data.permissions'));
        $this->assertContains('view-workplan-performance', $response->json('data.permissions'));
    }

    public function test_access_management_returns_permissions_grouped_by_module(): void
    {
        [$moh] = $this->seedModuleFixtures();
        $platformAdmin = $this->makePlatformAdmin();

        $this->actingAs($platformAdmin)
            ->getJson(route('api.access-management.index'))
            ->assertOk()
            ->assertJsonFragment(['code' => 'service_reporting'])
            ->assertJsonFragment(['name' => 'view-service-reports'])
            ->assertJsonPath('data.can_manage_modules', true);
    }

    /**
     * @return array{0:Mda,1:Mda}
     */
    protected function seedModuleFixtures(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $moh = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $hmb = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);

        $this->seed(ModuleSeeder::class);

        return [$moh, $hmb];
    }

    protected function makePlatformAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Platform Admin');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'platform',
            'state_code' => 'NG-NI',
            'mda_id' => null,
        ]);

        return $user;
    }

    protected function makeMdaAdmin(Mda $mda): User
    {
        $user = User::factory()->mdaUser($mda)->create();
        $user->assignRole('MDA Admin');
        $user->accessScopes()->create(['scope_type' => 'mda', 'state_code' => null, 'mda_id' => $mda->id]);

        return $user;
    }

    protected function mdaHasModule(Mda $mda, string $moduleCode): bool
    {
        return MdaModule::query()
            ->where('mda_id', $mda->id)
            ->where('enabled', true)
            ->whereHas('module', fn ($query) => $query->where('code', $moduleCode))
            ->exists();
    }
}
