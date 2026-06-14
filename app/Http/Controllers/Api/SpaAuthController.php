<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Support\DomainContext;

class SpaAuthController extends Controller
{
    public function login(LoginRequest $request, DomainContext $context): JsonResponse
    {
        $request->authenticate();
        if ($context->isMdaDomain() && ! $request->user()->hasPlatformAccess() && ! $request->user()->canAccessMda($context->mda()->id)) {
            Auth::guard('web')->logout();
            abort(403, 'This account does not have access to this MDA domain.');
        }
        if (! $context->isMdaDomain() && ! $context->platform()->allow_platform_login && ! $request->user()->hasPlatformAccess()) {
            Auth::guard('web')->logout();
            abort(403, 'This account must sign in through its assigned MDA domain.');
        }
        $request->session()->regenerate();

        return response()->json(['message' => 'Signed in successfully.']);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out successfully.']);
    }
}
