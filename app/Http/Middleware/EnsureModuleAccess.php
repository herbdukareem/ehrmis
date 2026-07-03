<?php

namespace App\Http\Middleware;

use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Mda;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    public function __construct(protected ModuleAccessService $modules)
    {
    }

    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $mdaId = $this->resolveMdaId($request);

        abort_unless(
            $this->modules->userCanAccessModule($user, $moduleCode, $mdaId),
            403,
            'This module is not enabled for the selected MDA.'
        );

        return $next($request);
    }

    protected function resolveMdaId(Request $request): ?int
    {
        if (str_contains((string) $request->route()?->uri(), 'access-management')) {
            return $request->user()?->primaryAccessibleMdaId();
        }

        $explicit = $request->input('mda_id') ?? $request->query('mda_id');

        if ($explicit) {
            return (int) $explicit;
        }

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Mda) {
                return (int) $parameter->id;
            }

            if (is_object($parameter) && isset($parameter->mda_id)) {
                return (int) $parameter->mda_id;
            }
        }

        return $request->user()?->primaryAccessibleMdaId();
    }
}
