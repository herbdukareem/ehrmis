<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user()?->loadMissing('mda', 'station');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'context' => $user ? [
                    'roles' => $user->getRoleNames()->values(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->values(),
                    'assigned_mda' => $user->mda?->only(['id', 'code', 'name', 'status']),
                    'assigned_station' => $user->station?->only(['id', 'mda_id', 'code', 'name', 'status']),
                    'has_global_access' => $user->hasGlobalMdaAccess(),
                ] : null,
            ],
        ];
    }
}
