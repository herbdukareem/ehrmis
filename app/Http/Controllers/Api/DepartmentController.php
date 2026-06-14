<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Department;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        $departments = Department::query()
            ->when(
                $request->user()->hasGlobalMdaAccess() && $request->integer('mda_id'),
                fn ($query) => $query->where('mda_id', $request->integer('mda_id'))
            )
            ->orderBy('name')
            ->get(['id', 'mda_id', 'code', 'name', 'description', 'status']);

        return response()->json(['data' => $departments]);
    }
}
