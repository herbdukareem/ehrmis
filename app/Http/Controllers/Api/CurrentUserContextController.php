<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Mda;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\DomainContext;

class CurrentUserContextController extends Controller
{
    public function show(Request $request, DomainContext $context): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type?->value,
                'status' => $user->status?->value,
                'roles' => $user->getRoleNames()->values(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values(),
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
