<?php

namespace App\Http\Controllers\Api;

use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Mda;
use App\Domain\Procurement\Exports\ProcurementReportExport;
use App\Domain\Procurement\Models\ProcurementPlan;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\ProcurementReportService;
use App\Services\AuditLogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ProcurementReportsController extends BudgetProcurementController
{
    public function options(Request $request, ModuleAccessService $modules)
    {
        $reports = $this->allowedReports($request);
        abort_unless(count($reports), 403);
        $data = $request->validate(['mda_id' => ['nullable', 'integer', 'min:1']]);
        $mdas = Mda::query()->visibleToUser($request->user())->orderBy('name')->get(['id', 'name', 'code'])
            ->filter(fn ($mda) => $modules->mdaHasModule($mda->id, 'budget_procurement'))->values();
        $mdaId = (int) ($data['mda_id'] ?? $mdas->first()?->id);
        $this->assertMda($request, $mdaId);
        abort_unless($mdas->contains('id', $mdaId), 403);
        $years = collect([now('Africa/Lagos')->year]);
        if ($request->user()->can('view-budget-cycles')) {
            $years = $years->merge(BudgetCycle::query()->where('mda_id', $mdaId)->pluck('year'));
        }
        if ($request->user()->can('view-procurement')) {
            $years = $years->merge(ProcurementPlan::query()->where('mda_id', $mdaId)->pluck('year'));
        }

        return $this->result(['mdas' => $mdas, 'mda_id' => $mdaId, 'years' => $years->unique()->sortDesc()->values(),
            'vendors' => count(array_diff(array_keys($reports), ['budget-utilization'])) ? Vendor::query()->where('mda_id', $mdaId)->orderBy('name')->get(['id', 'name', 'vendor_no']) : [],
            'reports' => $reports, 'can_export' => $request->user()->can('export-procurement-reports')]);
    }

    public function dashboard(Request $request, ProcurementReportService $service)
    {
        $allowed = $this->allowedReports($request);
        abort_unless(count($allowed), 403);
        $filters = $this->filters($request);
        $reports = [];
        foreach ($allowed as $type => $definition) {
            if ($type === 'budget-utilization' && ! empty($filters['vendor_id'])) {
                continue;
            }
            $reports[$type] = $service->build($type, $filters, 1, 5);
        }

        return $this->result(['reports' => $reports, 'filters' => $filters]);
    }

    public function show(Request $request, string $reportType, ProcurementReportService $service)
    {
        $this->authorizeReport($request, $reportType);
        $filters = $this->filters($request, $reportType);
        $pagination = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->result($service->build($reportType, $filters, $pagination['page'] ?? 1, $pagination['per_page'] ?? 25));
    }

    public function export(Request $request, string $reportType, string $format, ProcurementReportService $service, AuditLogService $audit)
    {
        $this->authorizeReport($request, $reportType);
        abort_unless($request->user()->can('export-procurement-reports'), 403);
        $filters = $this->filters($request, $reportType);
        $report = $service->build($reportType, $filters, 1, 25, $format === 'pdf' ? 1000 : 10000);
        $report['metadata'] = [
            'MDA' => Mda::query()->findOrFail($filters['mda_id'])->name,
            'Financial year' => $filters['year'] ?? 'All years',
            'Period' => $filters['date_from'].' to '.$filters['date_to'],
            'Supplier' => empty($filters['vendor_id']) ? 'All suppliers' : Vendor::query()->where('mda_id', $filters['mda_id'])->findOrFail($filters['vendor_id'])->name,
            'Generated (Lagos)' => now('Africa/Lagos')->format('Y-m-d H:i'),
            'Rows' => $report['summary']['count'],
        ];
        $report['basis'] = match ($reportType) {
            'budget-utilization' => 'Closing ledger balances at the cutoff date; period expenditure excludes opening expenditure. Available = allocated - committed - expenditure. Releases are shown separately. Currency: NGN.',
            'procurement-tracking' => 'Requisitions raised in the period, with current workflow status and delivery through the cutoff. Delivery is the mean accepted fraction of order lines, not a sum of different units. Later amendments are not historical snapshots.',
            'supplier-balances' => 'Approved invoices through the cutoff, including older unpaid invoices. Paid amounts use settlement dates through the cutoff; aging uses the cutoff date. Period start does not remove opening liabilities.',
            'payment-history' => 'Payments prepared, approved or settled in the period, with current status. Settled total includes only settlements within the period. Approved pending includes only selected activity records pending at cutoff.',
        };
        $filename = $reportType.'-'.$filters['mda_id'].'-'.$filters['date_to'].'.'.$format;
        if ($format === 'xlsx') {
            $response = Excel::download(new ProcurementReportExport($report), $filename);
        } else {
            $pdf = Pdf::loadView('reports.procurement', ['report' => $report])->setPaper('a3', 'landscape');
            $pdf->render();
            $canvas = $pdf->getDomPDF()->getCanvas();
            $canvas->page_text($canvas->get_width() - 140, $canvas->get_height() - 24, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 8);
            $response = $pdf->download($filename);
        }
        $audit->logExport('budget-procurement.'.$reportType, ['mda_id' => $filters['mda_id'], 'filters' => $filters, 'format' => $format, 'rows' => $report['summary']['count']]);

        return $response;
    }

    private function authorizeReport(Request $request, string $type): void
    {
        abort_unless(isset(ProcurementReportService::REPORTS[$type]), 404);
        abort_unless($request->user()->can(ProcurementReportService::REPORTS[$type]['permission']), 403);
    }

    private function allowedReports(Request $request): array
    {
        return array_filter(ProcurementReportService::REPORTS, fn ($report) => $request->user()->can($report['permission']));
    }

    private function filters(Request $request, ?string $type = null): array
    {
        $mdaId = $this->selectedMda($request);
        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'between:1900,2200'],
            'vendor_id' => ['nullable', 'integer', $this->reference(Vendor::class, $mdaId)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (! empty($filters['vendor_id']) && ($type === 'budget-utilization' || ! ($request->user()->can('view-procurement') || $request->user()->can('view-supplier-payables')))) {
            throw ValidationException::withMessages(['vendor_id' => 'Supplier filters apply only to supplier-linked procurement and payment records.']);
        }
        $year = $filters['year'] ?? now('Africa/Lagos')->year;
        $filters['date_from'] ??= $year.'-01-01';
        $filters['date_to'] ??= (int) $year === now('Africa/Lagos')->year ? now('Africa/Lagos')->toDateString() : $year.'-12-31';
        if ($filters['date_to'] < $filters['date_from'] || CarbonImmutable::parse($filters['date_from'])->diffInYears(CarbonImmutable::parse($filters['date_to'])) > 10) {
            throw ValidationException::withMessages(['date_to' => 'Choose an end date on or after the start date, within ten years.']);
        }

        return ['mda_id' => $mdaId, ...$filters];
    }
}
