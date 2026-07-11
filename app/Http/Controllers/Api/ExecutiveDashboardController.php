<?php

namespace App\Http\Controllers\Api;

use App\Domain\Dashboard\Services\ExecutiveDashboardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutiveDashboardController extends Controller
{
    public function __invoke(Request $request, ExecutiveDashboardService $dashboard): JsonResponse
    {
        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'cadre_id' => ['nullable', 'integer', 'exists:cadres,id'],
            'lga' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $dashboard->data($request->user(), $filters),
        ]);
    }
}
