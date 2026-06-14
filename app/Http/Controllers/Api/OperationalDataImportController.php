<?php

namespace App\Http\Controllers\Api;

use App\Domain\Imports\OperationalDataImportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OperationalDataImportController extends Controller
{
    public function store(Request $request, string $type, OperationalDataImportService $service): JsonResponse
    {
        abort_unless(in_array($type, OperationalDataImportService::TYPES, true), 404);
        abort_unless($request->user()->can('import-staff'), 403);
        abort_if(! $request->user()->hasGlobalMdaAccess() && ! $request->user()->mda_id, 403, 'An MDA assignment is required to import data.');

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);
        $result = $service->import($type, $validated['file'], $request->user());

        return response()->json([
            'message' => $type === 'staff-list'
                ? 'Staff list staged for review successfully.'
                : ucfirst($type).' imported successfully.',
            'data' => $result,
        ]);
    }

    public function template(Request $request, string $type, OperationalDataImportService $service): BinaryFileResponse
    {
        abort_unless(in_array($type, OperationalDataImportService::TYPES, true), 404);
        abort_unless($request->user()->can('import-staff'), 403);
        abort_if(! $request->user()->hasGlobalMdaAccess() && ! $request->user()->mda_id, 403, 'An MDA assignment is required to download import templates.');

        return Excel::download($service->template($type, $request->user()), $type.'-import-template.xlsx');
    }
}
