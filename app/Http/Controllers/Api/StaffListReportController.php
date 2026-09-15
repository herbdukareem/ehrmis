<?php

namespace App\Http\Controllers\Api;

use App\Domain\Staff\Exports\StaffListReportExport;
use App\Domain\Staff\Services\StaffListReportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StaffListReportRequest;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StaffListReportController extends Controller
{
    public function options(StaffListReportRequest $request, StaffListReportService $report): JsonResponse
    {
        return response()->json(['data' => $report->options($request->user())]);
    }

    public function index(StaffListReportRequest $request, StaffListReportService $report): JsonResponse
    {
        $filters = $request->validated();
        $types = $report->allowanceTypes();
        $staff = $report->query($request->user(), $filters)->paginate($filters['per_page'] ?? 500);

        return response()->json([
            'data' => $staff->getCollection()->map(fn ($record) => $report->row($record, $types)),
            'columns' => collect(StaffListReportService::COLUMNS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'allowance_types' => $types,
            'meta' => [
                'current_page' => $staff->currentPage(),
                'last_page' => $staff->lastPage(),
                'per_page' => $staff->perPage(),
                'total' => $staff->total(),
                'from' => $staff->firstItem(),
                'to' => $staff->lastItem(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function export(StaffListReportRequest $request, StaffListReportService $report): BinaryFileResponse
    {
        abort_unless($request->user()->can('export-reports'), 403);

        return Excel::download(
            new StaffListReportExport($report, $request->user(), $request->validated()),
            'staff-list-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }
}
