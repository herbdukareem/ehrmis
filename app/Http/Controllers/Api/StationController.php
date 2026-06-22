<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Station;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Station::class);

        $stations = Station::query()
            ->when($request->integer('mda_id'), fn ($query) => $query->where('mda_id', $request->integer('mda_id')))
            ->orderBy('name')
            ->get(['id', 'mda_id', 'code', 'name', 'description', 'status']);

        return response()->json(['data' => $stations]);
    }
}
