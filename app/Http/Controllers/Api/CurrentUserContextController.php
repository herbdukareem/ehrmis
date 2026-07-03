<?php

namespace App\Http\Controllers\Api;

use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Mda;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\DomainContext;

class CurrentUserContextController extends Controller
{
    public function show(Request $request, DomainContext $context, ModuleAccessService $modules): JsonResponse
    {
        $user = $request->user();
        $userPermissions = $user->getAllPermissions()->pluck('name')->values();
        $enabledModules = $modules->enabledModulesForUser($user)
            ->map(function ($module) use ($modules, $userPermissions): array {
                $payload = $modules->serializeModule($module);
                $payload['enabled'] = true;
                $payload['permissions'] = collect($payload['permissions'])
                    ->intersect($userPermissions)
                    ->values();

                return $payload;
            })
            ->values();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type?->value,
                'status' => $user->status?->value,
                'roles' => $user->getRoleNames()->values(),
                'permissions' => $userPermissions,
                'modules' => $enabledModules,
                'enabled_modules' => $enabledModules->pluck('code')->values(),
                'assigned_mda' => $user->mda?->only(['id', 'code', 'name', 'status']),
                'has_global_access' => $user->hasGlobalMdaAccess(),
                'accessible_mdas' => Mda::query()
                    ->visibleToUser($user)
                    ->orderBy('name')
                    ->get(['id', 'code', 'name', 'status']),
                'access_scopes' => $user->accessScopes()->with('mda')->get(),
                'branding' => $context->publicProfile(),
            ],
        ]);
    }
}
