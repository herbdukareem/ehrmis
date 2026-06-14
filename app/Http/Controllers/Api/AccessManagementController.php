<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Mda;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage-users') || $request->user()->can('manage-roles'), 403);
        $user = $request->user();

        $roles = Role::query()
            ->when(! $user->hasPlatformAccess(), fn ($query) => $query->whereNotIn('name', ['Super Admin', 'MIS Admin']))
            ->with('permissions')->orderBy('name')->get();

        return response()->json(['data' => [
            'users' => User::query()->visibleTo($user)->with(['mda', 'roles', 'accessScopes.mda'])->orderBy('name')->get(),
            'roles' => $roles,
            'permissions' => Permission::query()->orderBy('name')->get(['id', 'name']),
            'mdas' => Mda::query()->visibleToUser($user)->orderBy('name')->get(['id', 'code', 'name']),
            'can_manage_roles' => $user->can('manage-roles'),
            'scope_types' => ['platform', 'state', 'mda'],
        ]]);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('manage-roles') && $request->user()->hasPlatformAccess(), 403);
        $validated = $request->validate(['permissions' => ['array'], 'permissions.*' => ['string', 'exists:permissions,name']]);
        $role->syncPermissions($validated['permissions'] ?? []);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => 'Role permissions updated.', 'data' => $role->load('permissions')]);
    }

    public function updateUser(Request $request, User $managedUser): JsonResponse
    {
        abort_unless($request->user()->can('manage-users') && ($request->user()->hasPlatformAccess() || $request->user()->canAccessMda((int) $managedUser->mda_id)), 403);
        $validated = $request->validate([
            'roles' => ['array'], 'roles.*' => ['string', 'exists:roles,name'],
            'scope_type' => ['required', Rule::in(['platform', 'state', 'mda'])],
            'state_code' => ['nullable', 'string', 'max:20'],
            'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
        ]);
        abort_if($validated['scope_type'] === 'platform' && ! $request->user()->hasPlatformAccess(), 403);
        abort_if(! $request->user()->hasPlatformAccess() && collect($validated['roles'] ?? [])->intersect(['Super Admin', 'MIS Admin'])->isNotEmpty(), 403);
        if ($validated['scope_type'] === 'mda') {
            abort_unless($request->user()->canAccessMda((int) $validated['mda_id']), 403);
        }

        DB::transaction(function () use ($managedUser, $validated): void {
            $managedUser->syncRoles($validated['roles'] ?? []);
            $managedUser->accessScopes()->delete();
            $managedUser->accessScopes()->create([
                'scope_type' => $validated['scope_type'],
                'state_code' => $validated['state_code'] ?? null,
                'mda_id' => $validated['scope_type'] === 'mda' ? $validated['mda_id'] : null,
            ]);
            if ($validated['scope_type'] === 'mda') {
                $managedUser->update(['mda_id' => $validated['mda_id']]);
            }
        });

        return response()->json(['message' => 'User access updated.', 'data' => $managedUser->fresh(['roles', 'accessScopes.mda'])]);
    }
}
