<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Location;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Location::class);

        $locations = Location::query()
            ->orderBy('state')
            ->orderBy('lga')
            ->orderBy('town')
            ->get(['id', 'state', 'lga', 'ward', 'town', 'is_urban_center', 'status']);

        return response()->json(['data' => $locations]);
    }
}
