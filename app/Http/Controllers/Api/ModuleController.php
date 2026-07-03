<?php

namespace App\Http\Controllers\Api;

use App\Domain\Module\Models\MdaModule;
use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Mda;
use App\Http\Controllers\Controller;
use App\Http\Requests\Module\UpdateMdaModulesRequest;
use App\Support\AccessManagementRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function __construct(protected ModuleAccessService $modules)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $mdaId = $request->integer('mda_id') ?: null;
        $mda = $mdaId ? Mda::query()->findOrFail($mdaId) : null;

        if ($mda) {
            abort_unless($request->user()->canAccessMda($mda->id), 403);
        }

        return response()->json([
            'data' => $this->modules
                ->modulesVisibleTo($request->user(), $mda)
                ->map(fn ($module): array => $this->modules->serializeModule($module, $mda?->id))
                ->values(),
        ]);
    }

    public function mdaModules(Request $request, Mda $mda): JsonResponse
    {
        abort_unless($request->user()->canAccessMda($mda->id), 403);

        return response()->json([
            'data' => $this->modules
                ->modulesVisibleTo($request->user(), $mda)
                ->map(fn ($module): array => $this->modules->serializeModule($module, (int) $mda->id))
                ->values(),
        ]);
    }

    public function updateMdaModules(UpdateMdaModulesRequest $request, Mda $mda): JsonResponse
    {
        $assignments = collect($request->validated('modules'))
            ->map(fn (array $assignment): array => [
                'code' => $assignment['code'],
                'enabled' => (bool) $assignment['enabled'],
            ])
            ->values()
            ->all();

        $this->modules->syncMdaModules($mda, $assignments, $request->user());

        return response()->json([
            'message' => 'MDA module access updated.',
            'data' => $this->modules
                ->modulesVisibleTo($request->user(), $mda)
                ->map(fn ($module): array => $this->modules->serializeModule($module, (int) $mda->id))
                ->values(),
        ]);
    }

    public function permissions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->modules->permissionsGroupedFor($request->user()),
        ]);
    }

    public function roleTemplates(): JsonResponse
    {
        return response()->json([
            'data' => $this->modules->roleTemplatesGrouped(),
        ]);
    }

    public function serviceReports(Request $request): JsonResponse
    {
        $mdaId = $request->integer('mda_id') ?: $request->user()->primaryAccessibleMdaId();

        abort_unless(
            $this->modules->userCan($request->user(), 'service_reporting', 'view-service-reports', $mdaId),
            403,
            'You do not have access to service reports for this MDA.'
        );

        return response()->json([
            'data' => [
                'title' => 'MDA Service Reporting and Returns Module',
                'description' => 'This module will allow enabled MDAs to configure, submit, review, approve, and analyze service reports.',
            ],
        ]);
    }

    public static function mdaModuleAssignmentsFor(Request $request): array
    {
        if (! AccessManagementRules::canManageAccessScopes($request->user()) && ! $request->user()->hasPlatformAccess()) {
            return [];
        }

        return MdaModule::query()
            ->with(['module', 'mda'])
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
}
