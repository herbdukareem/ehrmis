<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Services\QualificationCatalogSyncService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MdaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Mda::class);

        $mdas = Mda::query()
            ->visibleToUser($request->user())
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description', 'status']);

        return response()->json(['data' => $mdas]);
    }

    public function store(Request $request, QualificationCatalogSyncService $catalogSyncService): JsonResponse
    {
        $this->authorize('create', Mda::class);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('mdas', 'code')],
            'name' => ['required', 'string', 'max:255', Rule::unique('mdas', 'name')],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $mda = DB::transaction(function () use ($validated, $catalogSyncService): Mda {
            $mda = Mda::query()->create([
                'code' => strtoupper($validated['code']),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 'active',
            ]);

            $catalogSyncService->syncDefaultSalaryScalesForMda($mda);
            $catalogSyncService->syncCeilingsForMda($mda);

            return $mda->fresh();
        });

        return response()->json([
            'message' => 'MDA created with standard salary scales and qualification ceilings.',
            'data' => $mda->only(['id', 'code', 'name', 'description', 'status']),
        ], 201);
    }
}
