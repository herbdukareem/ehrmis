<?php

namespace App\Domain\Module\Services;

use App\Domain\Module\Models\MdaModule;
use App\Domain\Module\Models\Module;
use App\Domain\Module\Models\ModuleRoleTemplate;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ModuleAccessService
{
    public function __construct(protected AuditLogService $auditLogService)
    {
    }

    public function userCanAccessModule(User $user, string $moduleCode, ?int $mdaId = null): bool
    {
        $module = $this->moduleByCode($moduleCode);

        if (! $module) {
            return false;
        }

        if ($mdaId !== null) {
            return $user->canAccessMda($mdaId)
                && $this->mdaHasModule($mdaId, $moduleCode);
        }

        if ($user->hasGlobalMdaAccess()) {
            return true;
        }

        return $user->accessibleMdaIds()
            ->contains(fn (int $accessibleMdaId): bool => $this->mdaHasModule($accessibleMdaId, $moduleCode));
    }

    public function mdaHasModule(int $mdaId, string $moduleCode): bool
    {
        $this->ensureDefaultAssignmentsForMda($mdaId);

        return MdaModule::query()
            ->where('mda_id', $mdaId)
            ->where('enabled', true)
            ->whereHas('module', fn ($query) => $query->active()->where('code', $moduleCode))
            ->exists();
    }

    public function userCan(User $user, string $moduleCode, string $permission, ?int $mdaId = null): bool
    {
        return $this->userCanAccessModule($user, $moduleCode, $mdaId)
            && $this->moduleHasPermission($moduleCode, $permission)
            && $user->can($permission);
    }

    public function enabledModulesForUser(User $user): Collection
    {
        if ($user->hasGlobalMdaAccess()) {
            return Module::query()
                ->active()
                ->with('permissions')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        }

        $mdaIds = $user->accessibleMdaIds();

        if ($mdaIds->isEmpty()) {
            return collect();
        }

        return Module::query()
            ->active()
            ->whereHas('mdaModules', fn ($query) => $query
                ->whereIn('mda_id', $mdaIds->all())
                ->where('enabled', true))
            ->with('permissions')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function enabledModulesForMda(Mda $mda): Collection
    {
        return Module::query()
            ->active()
            ->whereHas('mdaModules', fn ($query) => $query
                ->where('mda_id', $mda->id)
                ->where('enabled', true))
            ->with('permissions')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function modulesVisibleTo(User $user, ?Mda $mda = null): Collection
    {
        if ($mda) {
            return Module::query()
                ->active()
                ->with(['permissions', 'mdaModules' => fn ($query) => $query->where('mda_id', $mda->id)])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        }

        if ($user->hasGlobalMdaAccess()) {
            return Module::query()
                ->active()
                ->with('permissions')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        }

        return $this->enabledModulesForUser($user);
    }

    public function permissionsGroupedFor(User $user): Collection
    {
        $modules = Module::query()
            ->active()
            ->with('permissions')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $modules
            ->map(function (Module $module) use ($user): array {
                $permissions = $module->permissions
                    ->filter(fn ($permission): bool => $this->permissionVisibleToUser($user, $permission->permission_name))
                    ->map(fn ($permission): array => [
                        'id' => $permission->id,
                        'name' => $permission->permission_name,
                        'guard_name' => 'web',
                    ])
                    ->values();

                return [
                    'module' => $this->serializeModule($module),
                    'permissions' => $permissions,
                ];
            })
            ->filter(fn (array $group): bool => $group['permissions']->isNotEmpty())
            ->values();
    }

    public function roleTemplatesGrouped(): Collection
    {
        return Module::query()
            ->active()
            ->with(['roleTemplates' => fn ($query) => $query->active()->with('permissions')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Module $module): array => [
                'module' => $this->serializeModule($module),
                'templates' => $module->roleTemplates
                    ->map(fn (ModuleRoleTemplate $template): array => [
                        'id' => $template->id,
                        'module_id' => $template->module_id,
                        'name' => $template->name,
                        'code' => $template->code,
                        'description' => $template->description,
                        'scope_type' => $template->scope_type,
                        'status' => $template->status,
                        'permissions' => $template->permissions->pluck('permission_name')->values(),
                    ])
                    ->values(),
            ])
            ->filter(fn (array $group): bool => $group['templates']->isNotEmpty())
            ->values();
    }

    /**
     * @param  list<array{code:string,enabled:bool}>  $assignments
     */
    public function syncMdaModules(Mda $mda, array $assignments, User $actor): Collection
    {
        return DB::transaction(function () use ($mda, $assignments, $actor): Collection {
            return collect($assignments)
                ->map(function (array $assignment) use ($mda, $actor): MdaModule {
                    $module = Module::query()->where('code', $assignment['code'])->firstOrFail();
                    $enabled = (bool) $assignment['enabled'];
                    $mdaModule = MdaModule::query()->firstOrNew([
                        'mda_id' => $mda->id,
                        'module_id' => $module->id,
                    ]);
                    $before = $mdaModule->exists ? $mdaModule->toArray() : [];
                    $changed = ! $mdaModule->exists || (bool) $mdaModule->enabled !== $enabled;

                    $mdaModule->fill([
                        'enabled' => $enabled,
                        'enabled_by' => $enabled ? $actor->id : $mdaModule->enabled_by,
                        'enabled_at' => $enabled ? now() : $mdaModule->enabled_at,
                        'disabled_at' => $enabled ? null : now(),
                    ])->save();

                    if ($changed) {
                        $this->auditLogService->log(
                            $enabled ? 'module_access.enabled' : 'module_access.disabled',
                            $mdaModule,
                            $before,
                            $mdaModule->fresh()->toArray(),
                            [
                                'source' => 'module_access',
                                'mda_id' => $mda->id,
                                'module_code' => $module->code,
                                'actor_user_id' => $actor->id,
                            ],
                        );
                    }

                    return $mdaModule->fresh('module');
                })
                ->values();
        });
    }

    public function serializeModule(Module $module, ?int $mdaId = null): array
    {
        $assignment = $mdaId !== null
            ? $module->mdaModules->firstWhere('mda_id', $mdaId)
            : $module->mdaModules->first();

        return [
            'id' => $module->id,
            'code' => $module->code,
            'name' => $module->name,
            'description' => $module->description,
            'category' => $module->category,
            'icon' => $module->icon,
            'status' => $module->status,
            'sort_order' => $module->sort_order,
            'enabled' => $assignment ? (bool) $assignment->enabled : null,
            'permissions' => $module->relationLoaded('permissions')
                ? $module->permissions->pluck('permission_name')->values()
                : collect(),
        ];
    }

    protected function moduleByCode(string $moduleCode): ?Module
    {
        return Module::query()->active()->where('code', $moduleCode)->first();
    }

    protected function ensureDefaultAssignmentsForMda(int $mdaId): void
    {
        if (MdaModule::query()->where('mda_id', $mdaId)->exists()) {
            return;
        }

        $mda = Mda::query()->find($mdaId);

        if (! $mda) {
            return;
        }

        $defaultModules = ['staff_registry', 'legacy_import', 'movement_budget', 'dashboards_analytics', 'settings', 'access_management'];
        $name = strtoupper((string) $mda->name);
        $code = strtoupper((string) $mda->code);

        if (
            str_contains($code, 'HMB')
            || str_contains($name, 'HMB')
            || str_contains($name, 'HOSPITAL MANAGEMENT BOARD')
            || str_contains($name, 'HOSPITALS MANAGEMENT BOARD')
        ) {
            $defaultModules[] = 'service_reporting';
        }

        Module::query()
            ->whereIn('code', $defaultModules)
            ->pluck('id')
            ->each(fn (int $moduleId) => MdaModule::query()->updateOrCreate(
                ['mda_id' => $mdaId, 'module_id' => $moduleId],
                [
                    'enabled' => true,
                    'enabled_at' => now(),
                    'disabled_at' => null,
                ],
            ));
    }

    protected function moduleHasPermission(string $moduleCode, string $permission): bool
    {
        return Module::query()
            ->where('code', $moduleCode)
            ->whereHas('permissions', fn ($query) => $query->where('permission_name', $permission))
            ->exists();
    }

    protected function permissionVisibleToUser(User $user, string $permissionName): bool
    {
        if ($user->hasPlatformAccess() || $user->hasAnyRole(['Super Admin', 'Platform Admin', 'MIS Admin'])) {
            return true;
        }

        return in_array($permissionName, \App\Support\AccessManagementRules::mdaRolePermissionNames(), true);
    }
}
