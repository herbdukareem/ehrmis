<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Mda;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
