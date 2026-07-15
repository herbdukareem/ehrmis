<?php

namespace App\Http\Controllers\Api;

use App\Domain\Module\Models\MdaModule;
use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessManagementRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class AccessManagementController extends Controller
{
    public function index(Request $request, ModuleAccessService $modules): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $user = $request->user();
        $roles = AccessManagementRules::visibleRolesQuery($user)
            ->with(['permissions', 'mda'])
            ->orderBy('scope')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'users' => AccessManagementRules::manageableUsersQuery($user)->get(),
                'roles' => $roles->map(fn (Role $role): array => $this->serializeRole($role, $user))->values(),
                'permissions' => $this->availablePermissionsFor($user),
                'permissions_by_module' => $modules->permissionsGroupedFor($user),
                'modules' => $modules
                    ->modulesVisibleTo($user)
                    ->map(fn ($module): array => $modules->serializeModule($module))
                    ->values(),
                'mda_module_assignments' => $this->mdaModuleAssignmentsFor($user),
                'role_templates_by_module' => $modules->roleTemplatesGrouped(),
                'mdas' => Mda::query()->visibleToUser($user)->orderBy('name')->get(['id', 'code', 'name']),
                'departments' => Department::query()->orderBy('name')->get(['id', 'mda_id', 'code', 'name']),
                'role_scope_options' => $this->roleScopeOptionsFor($user),
                'mda_role_permissions' => AccessManagementRules::mdaRolePermissionNames(),
                'can_manage_roles' => $user->can('manage-roles'),
                'can_manage_modules' => $user->hasPlatformAccess()
                    && $user->can('manage-platform-settings')
                    && $user->hasAnyRole(['Super Admin', 'Platform Admin', 'MIS Admin']),
                'can_manage_access_scopes' => AccessManagementRules::canManageAccessScopes($user),
                'can_manage_global_roles' => AccessManagementRules::canManageGlobalRoles($user),
                'can_manage_all_roles' => AccessManagementRules::canManageAllRoles($user),
                'can_manage_own_mda_roles' => AccessManagementRules::canManageOwnMdaRoles($user),
                'can_manage_users_in_own_mda' => AccessManagementRules::canManageUsersInOwnMda($user),
                'can_create_users' => AccessManagementRules::canCreateUser($user),
                'can_manage_user_status' => AccessManagementRules::canManageUserStatus($user),
                'scope_types' => ['platform', 'state', 'mda', 'department'],
                'user_statuses' => array_map(fn (RecordStatus $status): string => $status->value, RecordStatus::cases()),
            ],
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless(AccessManagementRules::canCreateUser($actor), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::in(array_map(fn (RecordStatus $status): string => $status->value, RecordStatus::cases()))],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'scope_type' => ['sometimes', Rule::in(['platform', 'state', 'mda', 'department'])],
            'state_code' => ['nullable', 'string', 'max:20'],
            'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
            'mda_ids' => ['nullable', 'array'],
            'mda_ids.*' => ['integer', 'exists:mdas,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
        ]);

        if (! AccessManagementRules::canManageAccessScopes($actor)) {
            $this->rejectUnauthorizedScopeMutation($request);
            $validated['scope_type'] = 'mda';
            $validated['mda_id'] = (int) $actor->mda_id;
            $validated['mda_ids'] = [];
        }

        $selectedRoles = Role::query()
            ->with('mda')
            ->whereKey($validated['role_ids'] ?? [])
            ->get();

        if ($selectedRoles->count() !== count($validated['role_ids'] ?? [])) {
            throw ValidationException::withMessages([
                'role_ids' => 'One or more selected roles could not be resolved.',
            ]);
        }

        $scopeState = $this->resolveScopeStateForCreate($validated, $actor);
        $primaryMdaId = $scopeState['primary_mda_id'];
        $placeholderUser = new User([
            'mda_id' => $primaryMdaId,
            'user_type' => $this->inferUserType($selectedRoles, null),
        ]);

        $this->assertRolesAssignable($actor, $placeholderUser, $selectedRoles, $primaryMdaId);

        $user = DB::transaction(function () use ($validated, $selectedRoles, $scopeState): User {
            $user = User::query()->create([
                'mda_id' => $scopeState['primary_mda_id'],
                'name' => $validated['name'],
                'email' => strtolower((string) $validated['email']),
                'password' => Hash::make($validated['password']),
                'status' => $validated['status'],
                'user_type' => $this->inferUserType($selectedRoles, null),
            ]);

            $user->syncRoles($selectedRoles);
            $this->syncUserScopes($user, $scopeState);
            $user->forceFill([
                'user_type' => $this->inferUserType($selectedRoles, $user->user_type?->value),
            ])->save();

            return $user->fresh(['mda', 'roles.mda', 'accessScopes.mda', 'accessScopes.department']);
        });

        return response()->json([
            'message' => 'User created.',
            'data' => $user,
        ], 201);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $validated = $this->validateRolePayload($request);
        $role = DB::transaction(function () use ($validated): Role {
            $role = Role::query()->create([
                'name' => $validated['name'],
                'guard_name' => 'web',
                'scope' => $validated['scope'],
                'mda_id' => $validated['mda_id'],
            ]);

            $role->syncPermissions($validated['permissions']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->fresh(['permissions', 'mda']);
        });

        return response()->json([
            'message' => 'Role created.',
            'data' => $this->serializeRole($role, $request->user()),
        ], 201);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        $validated = $this->validateRolePayload($request, $role);
        $role = DB::transaction(function () use ($role, $validated): Role {
            $role->fill([
                'name' => $validated['name'],
                'scope' => $validated['scope'],
                'mda_id' => $validated['mda_id'],
            ])->save();

            $role->syncPermissions($validated['permissions']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->fresh(['permissions', 'mda']);
        });

        return response()->json([
            'message' => 'Role updated.',
            'data' => $this->serializeRole($role, $request->user()),
        ]);
    }

    public function destroyRole(Request $request, Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => 'Role deleted.']);
    }

    public function updateUser(Request $request, User $managedUser): JsonResponse
    {
        $actor = $request->user();

        abort_unless(AccessManagementRules::canManageUser($actor, $managedUser), 403);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($managedUser->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'status' => ['sometimes', Rule::in(array_map(fn (RecordStatus $status): string => $status->value, RecordStatus::cases()))],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'scope_type' => ['sometimes', Rule::in(['platform', 'state', 'mda', 'department'])],
            'state_code' => ['nullable', 'string', 'max:20'],
            'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
            'mda_ids' => ['nullable', 'array'],
            'mda_ids.*' => ['integer', 'exists:mdas,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
        ]);

        if (! AccessManagementRules::canManageAccessScopes($actor)) {
            $this->rejectUnauthorizedScopeMutation($request);
        }

        if (Arr::has($validated, 'status') && ! AccessManagementRules::canManageUserStatus($actor)) {
            abort(403);
        }

        if ((Arr::has($validated, 'name') || Arr::has($validated, 'email') || filled($validated['password'] ?? null)) && ! $actor->can('update-users')) {
            abort(403);
        }

        $selectedRoles = Role::query()
            ->with('mda')
            ->whereKey($validated['role_ids'] ?? $managedUser->roles->pluck('id')->all())
            ->get();

        if (array_key_exists('role_ids', $validated) && $selectedRoles->count() !== count($validated['role_ids'] ?? [])) {
            throw ValidationException::withMessages([
                'role_ids' => 'One or more selected roles could not be resolved.',
            ]);
        }

        $scopeState = AccessManagementRules::canManageAccessScopes($actor)
            ? $this->resolveSubmittedScopeState($validated, $managedUser)
            : $this->currentScopeState($managedUser);

        $this->assertRolesAssignable($actor, $managedUser, $selectedRoles, $scopeState['primary_mda_id']);

        DB::transaction(function () use ($actor, $managedUser, $selectedRoles, $scopeState, $validated): void {
            $attributes = [];

            if (Arr::has($validated, 'name')) {
                $attributes['name'] = $validated['name'];
            }

            if (Arr::has($validated, 'email')) {
                $attributes['email'] = strtolower((string) $validated['email']);
            }

            if (Arr::has($validated, 'status')) {
                $attributes['status'] = $validated['status'];
            }

            if (filled($validated['password'] ?? null)) {
                $attributes['password'] = Hash::make($validated['password']);
            }

            $managedUser->syncRoles($selectedRoles);

            if ($attributes !== []) {
                $managedUser->fill($attributes);
            }

            if (! AccessManagementRules::canManageAccessScopes($actor)) {
                $managedUser->forceFill([
                    'user_type' => $this->inferUserType($selectedRoles, $managedUser->user_type?->value),
                ])->save();

                return;
            }

            $this->syncUserScopes($managedUser, $scopeState);
            $managedUser->forceFill([
                'user_type' => $this->inferUserType($selectedRoles, $managedUser->user_type?->value),
            ])->save();
        });

        return response()->json([
            'message' => AccessManagementRules::canManageAccessScopes($actor)
                ? 'User access updated.'
                : 'User roles updated.',
            'data' => $managedUser->fresh(['mda', 'roles.mda', 'accessScopes.mda', 'accessScopes.department']),
        ]);
    }

    /**
     * @return array{0:string,1:string}|list<string>
     */
    protected function roleScopeOptionsFor(User $user): array
    {
        if (AccessManagementRules::canManageAllRoles($user)) {
            return [Role::SCOPE_GLOBAL, Role::SCOPE_MDA];
        }

        if (AccessManagementRules::canManageGlobalRoles($user)) {
            return [Role::SCOPE_GLOBAL];
        }

        if (AccessManagementRules::canManageOwnMdaRoles($user)) {
            return [Role::SCOPE_MDA];
        }

        return [];
    }

    /**
     * @return Collection<int, array{id:int,name:string}>
     */
    protected function availablePermissionsFor(User $user): Collection
    {
        $query = Permission::query()->orderBy('name');

        if (AccessManagementRules::canManageAllRoles($user) || AccessManagementRules::canManageGlobalRoles($user)) {
            return $query->get(['id', 'name']);
        }

        return $query
            ->whereIn('name', AccessManagementRules::mdaRolePermissionNames())
            ->get(['id', 'name']);
    }

    /**
     * @return array<int, mixed>
     */
    protected function mdaModuleAssignmentsFor(User $user): array
    {
        $visibleMdaIds = Mda::query()->visibleToUser($user)->pluck('id');

        if ($visibleMdaIds->isEmpty()) {
            return [];
        }

        return MdaModule::query()
            ->with('module')
            ->whereIn('mda_id', $visibleMdaIds)
            ->get()
            ->groupBy('mda_id')
            ->map(fn ($assignments) => $assignments
                ->map(fn (MdaModule $assignment): array => [
                    'mda_id' => $assignment->mda_id,
                    'module_code' => $assignment->module?->code,
                    'module_name' => $assignment->module?->name,
                    'enabled' => (bool) $assignment->enabled,
                    'enabled_at' => $assignment->enabled_at,
                    'disabled_at' => $assignment->disabled_at,
                ])
                ->values())
            ->all();
    }

    protected function serializeRole(Role $role, User $actor): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'scope' => $role->scope,
            'mda_id' => $role->mda_id,
            'mda' => $role->mda?->only(['id', 'code', 'name']),
            'permissions' => $role->permissions
                ->map(fn ($permission): array => ['id' => $permission->id, 'name' => $permission->name])
                ->values()
                ->all(),
            'can_manage_definition' => AccessManagementRules::roleCanBeManagedBy($actor, $role),
        ];
    }

    /**
     * @return array{name:string,scope:string,mda_id:int|null,permissions:array<int,string>}
     */
    protected function validateRolePayload(Request $request, ?Role $role = null): array
    {
        $actor = $request->user();
        $allowedScopes = $this->roleScopeOptionsFor($actor);

        abort_if($allowedScopes === [], 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['sometimes', Rule::in($allowedScopes)],
            'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $scope = $validated['scope'] ?? $role?->scope ?? (in_array(Role::SCOPE_GLOBAL, $allowedScopes, true) ? Role::SCOPE_GLOBAL : Role::SCOPE_MDA);

        if ($scope === Role::SCOPE_GLOBAL) {
            abort_unless(AccessManagementRules::canManageGlobalRoles($actor), 403);
            $mdaId = null;
        } else {
            abort_unless(
                AccessManagementRules::canManageAllRoles($actor) || AccessManagementRules::canManageOwnMdaRoles($actor),
                403
            );

            $mdaId = AccessManagementRules::canManageOwnMdaRoles($actor) && ! AccessManagementRules::canManageAllRoles($actor)
                ? (int) $actor->mda_id
                : (int) ($validated['mda_id'] ?? 0);

            if (! $mdaId) {
                throw ValidationException::withMessages([
                    'mda_id' => 'An MDA is required for MDA-scoped roles.',
                ]);
            }
        }

        $name = trim((string) $validated['name']);
        $permissions = collect($validated['permissions'] ?? [])->unique()->values()->all();

        if ($scope === Role::SCOPE_MDA && AccessManagementRules::mdaRoleNameReserved($name)) {
            throw ValidationException::withMessages([
                'name' => 'This role name is reserved for global system roles.',
            ]);
        }

        if ($scope === Role::SCOPE_MDA && array_diff($permissions, AccessManagementRules::mdaRolePermissionNames()) !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'MDA roles may only use allowed MDA-level permissions.',
            ]);
        }

        $nameTaken = Role::query()
            ->where('guard_name', 'web')
            ->where('name', $name)
            ->where('scope', $scope)
            ->when(
                $scope === Role::SCOPE_MDA,
                fn ($query) => $query->where('mda_id', $mdaId),
                fn ($query) => $query->whereNull('mda_id')
            )
            ->when($role, fn ($query) => $query->whereKeyNot($role->id))
            ->exists();

        if ($nameTaken) {
            throw ValidationException::withMessages([
                'name' => $scope === Role::SCOPE_GLOBAL
                    ? 'A global role with this name already exists.'
                    : 'An MDA role with this name already exists for the selected MDA.',
            ]);
        }

        return [
            'name' => $name,
            'scope' => $scope,
            'mda_id' => $mdaId,
            'permissions' => $permissions,
        ];
    }

    protected function rejectUnauthorizedScopeMutation(Request $request): void
    {
        foreach (['scope_type', 'state_code', 'mda_id', 'mda_ids', 'department_ids'] as $field) {
            if ($request->has($field)) {
                abort(403);
            }
        }
    }

    /**
     * @return array{scope_type:string,state_code:?string,primary_mda_id:?int,accessible_mda_ids:array<int,int>,accessible_department_ids:array<int,int>}
     */
    protected function currentScopeState(User $managedUser): array
    {
        $departmentScopeIds = $managedUser->accessibleDepartmentIds()->all();
        $mdaScopeIds = $managedUser->accessibleMdaIds()->all();
        $nonMdaScope = $managedUser->accessScopes()
            ->whereIn('scope_type', ['platform', 'state', 'department'])
            ->first();
        $scopeType = $nonMdaScope?->scope_type ?? ($departmentScopeIds !== [] ? 'department' : 'mda');
        $primaryMdaId = $managedUser->mda_id
            ? (int) $managedUser->mda_id
            : ($managedUser->accessScopes()->where('scope_type', 'department')->value('mda_id') ?: ($mdaScopeIds[0] ?? null));

        return [
            'scope_type' => $scopeType,
            'state_code' => $nonMdaScope?->state_code,
            'primary_mda_id' => $primaryMdaId ? (int) $primaryMdaId : null,
            'accessible_mda_ids' => $mdaScopeIds,
            'accessible_department_ids' => $departmentScopeIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{scope_type:string,state_code:?string,primary_mda_id:?int,accessible_mda_ids:array<int,int>,accessible_department_ids:array<int,int>}
     */
    protected function resolveSubmittedScopeState(array $validated, User $managedUser): array
    {
        $currentState = $this->currentScopeState($managedUser);
        $scopeType = $validated['scope_type'] ?? $currentState['scope_type'];

        if ($scopeType === 'mda') {
            $primaryMdaId = isset($validated['mda_id'])
                ? (int) $validated['mda_id']
                : $currentState['primary_mda_id'];

            if (! $primaryMdaId) {
                throw ValidationException::withMessages([
                    'mda_id' => 'A primary MDA is required for MDA-scoped users.',
                ]);
            }

            $accessibleMdaIds = collect([$primaryMdaId, ...($validated['mda_ids'] ?? $currentState['accessible_mda_ids'])])
                ->filter()
                ->map(fn ($mdaId): int => (int) $mdaId)
                ->unique()
                ->values()
                ->all();

            if ($accessibleMdaIds === []) {
                throw ValidationException::withMessages([
                    'mda_ids' => 'At least one MDA must be assigned.',
                ]);
            }

            return [
                'scope_type' => 'mda',
                'state_code' => null,
                'primary_mda_id' => $primaryMdaId,
                'accessible_mda_ids' => $accessibleMdaIds,
                'accessible_department_ids' => [],
            ];
        }

        if ($scopeType === 'department') {
            $primaryMdaId = isset($validated['mda_id'])
                ? (int) $validated['mda_id']
                : $currentState['primary_mda_id'];

            if (! $primaryMdaId) {
                throw ValidationException::withMessages([
                    'mda_id' => 'A primary MDA is required for department-scoped users.',
                ]);
            }

            $departmentIds = collect($validated['department_ids'] ?? $currentState['accessible_department_ids'])
                ->filter()
                ->map(fn ($departmentId): int => (int) $departmentId)
                ->unique()
                ->values();

            if ($departmentIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'department_ids' => 'At least one department must be assigned.',
                ]);
            }

            $invalidDepartmentIds = Department::query()
                ->whereIn('id', $departmentIds->all())
                ->where('mda_id', '!=', $primaryMdaId)
                ->pluck('id')
                ->all();

            if ($invalidDepartmentIds !== []) {
                throw ValidationException::withMessages([
                    'department_ids' => 'All selected departments must belong to the chosen primary MDA.',
                ]);
            }

            return [
                'scope_type' => 'department',
                'state_code' => null,
                'primary_mda_id' => $primaryMdaId,
                'accessible_mda_ids' => [$primaryMdaId],
                'accessible_department_ids' => $departmentIds->all(),
            ];
        }

        return [
            'scope_type' => $scopeType,
            'state_code' => $validated['state_code'] ?? $currentState['state_code'] ?? 'NG-NI',
            'primary_mda_id' => null,
            'accessible_mda_ids' => [],
            'accessible_department_ids' => [],
        ];
    }

    protected function assertRolesAssignable(User $actor, User $managedUser, Collection $roles, ?int $primaryMdaId): void
    {
        foreach ($roles as $role) {
            if (! $role instanceof Role) {
                continue;
            }

            if (AccessManagementRules::canManageAllRoles($actor)) {
                if ($role->scope === Role::SCOPE_MDA && (int) $role->mda_id !== (int) $primaryMdaId) {
                    throw ValidationException::withMessages([
                        'role_ids' => 'MDA-scoped roles may only be assigned to users whose primary MDA matches the role MDA.',
                    ]);
                }

                continue;
            }

            if (AccessManagementRules::canManageAccessScopes($actor)) {
                if ($role->scope === Role::SCOPE_MDA && (int) $role->mda_id !== (int) $primaryMdaId) {
                    throw ValidationException::withMessages([
                        'role_ids' => 'MDA-scoped roles may only be assigned to users in the same MDA.',
                    ]);
                }

                continue;
            }

            if (
                $role->scope !== Role::SCOPE_MDA
                || (int) $role->mda_id !== (int) $actor->mda_id
                || (int) $managedUser->mda_id !== (int) $actor->mda_id
            ) {
                throw ValidationException::withMessages([
                    'role_ids' => 'You may only assign MDA roles from your own MDA to users in your own MDA.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{scope_type:string,state_code:?string,primary_mda_id:?int,accessible_mda_ids:array<int,int>,accessible_department_ids:array<int,int>}
     */
    protected function resolveScopeStateForCreate(array $validated, User $actor): array
    {
        if (! AccessManagementRules::canManageAccessScopes($actor)) {
            return [
                'scope_type' => 'mda',
                'state_code' => null,
                'primary_mda_id' => (int) $actor->mda_id,
                'accessible_mda_ids' => [(int) $actor->mda_id],
                'accessible_department_ids' => [],
            ];
        }

        return $this->resolveSubmittedScopeState($validated, new User([
            'mda_id' => $validated['mda_id'] ?? null,
        ]));
    }

    /**
     * @param  array{scope_type:string,state_code:?string,primary_mda_id:?int,accessible_mda_ids:array<int,int>,accessible_department_ids:array<int,int>}  $scopeState
     */
    protected function syncUserScopes(User $user, array $scopeState): void
    {
        $user->accessScopes()->delete();

        if ($scopeState['scope_type'] === 'mda') {
            foreach ($scopeState['accessible_mda_ids'] as $mdaId) {
                $user->accessScopes()->create([
                    'scope_type' => 'mda',
                    'state_code' => null,
                    'mda_id' => $mdaId,
                ]);
            }

            $user->forceFill(['mda_id' => $scopeState['primary_mda_id']])->save();

            return;
        }

        if ($scopeState['scope_type'] === 'department') {
            foreach ($scopeState['accessible_department_ids'] as $departmentId) {
                $user->accessScopes()->create([
                    'scope_type' => 'department',
                    'state_code' => null,
                    'mda_id' => $scopeState['primary_mda_id'],
                    'department_id' => $departmentId,
                ]);
            }

            $user->forceFill(['mda_id' => $scopeState['primary_mda_id']])->save();

            return;
        }

        $user->accessScopes()->create([
            'scope_type' => $scopeState['scope_type'],
            'state_code' => $scopeState['state_code'],
            'mda_id' => null,
            'department_id' => null,
        ]);
        $user->forceFill(['mda_id' => null])->save();
    }

    protected function inferUserType(Collection $roles, ?string $fallback): string
    {
        $roleNames = $roles->pluck('name');

        return match (true) {
            $roleNames->contains('Super Admin') => 'super_admin',
            $roleNames->contains('MIS Admin') => 'mis_admin',
            $roleNames->contains('MDA Admin') => 'mda_admin',
            $roleNames->contains('HR Officer') => 'hr_officer',
            $roleNames->contains('Budget Officer') => 'budget_officer',
            $roleNames->contains('Payroll Auditor') => 'payroll_auditor',
            $roleNames->contains('Approval Officer') => 'approval_officer',
            $roleNames->contains('Report Viewer') => 'report_viewer',
            default => $fallback ?: 'report_viewer',
        };
    }
}
