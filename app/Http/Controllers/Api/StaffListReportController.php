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
        $columns = $report->reportColumns($filters, $types);
        $grouped = ($filters['report_type'] ?? 'staff_list') !== 'staff_list';
        $staff = $report->query($request->user(), $filters)->paginate($filters['per_page'] ?? 500);
        $rows = $staff->getCollection()->map(function ($record) use ($report, $types, $columns, $grouped, $filters) {
            $row = array_intersect_key($report->row($record, $types), $columns + ['id' => '', 'allowances' => '', 'unpriced_allowances' => '']);
            if ($grouped) {
                $row['group_key'] = $report->groupKey($record, $filters['report_type']);
            }

            return $row;
        });

        return response()->json([
            'data' => $rows,
            'columns' => $report->columnDefinitions($columns),
            'allowance_types' => $types,
            'groups' => $grouped ? $report->groupedRows($request->user(), $filters) : [],
            'meta' => [
                'current_page' => $staff->currentPage(),
                'last_page' => $staff->lastPage(),
                'per_page' => $staff->perPage(),
                'total' => $staff->total(),
                'from' => $staff->firstItem(),
                'to' => $staff->lastItem(),
                'generated_at' => now()->toIso8601String(),
                'report_type' => $filters['report_type'] ?? 'staff_list',
                'staff_total' => $staff->total(),
            ],
        ]);
    }

    public function export(StaffListReportRequest $request, StaffListReportService $report): BinaryFileResponse
    {
        abort_unless($request->user()->can('export-reports'), 403);
        $type = $request->validated('report_type', 'staff_list');
        $filename = $type === 'staff_list' ? 'staff-list' : 'staff-by-'.str_replace('_', '-', $type);

        return Excel::download(
            new StaffListReportExport($report, $request->user(), $request->validated()),
            $filename.'-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }
}
