<?php

namespace App\Domain\ServiceReporting\Services;

use App\Domain\ServiceReporting\Exports\ArrayReportExport;
use App\Domain\ServiceReporting\Models\ReportSubmission;
use App\Models\User;
use App\Services\AuditLogService;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportService
{
    public function __construct(protected AuditLogService $auditLogService)
    {
    }

    public function submission(ReportSubmission $submission, User $actor): BinaryFileResponse
    {
        $submission->loadMissing(['template.sections.indicators.dimensions', 'period', 'mda', 'station', 'values']);
        $rows = [
            ['Template', $submission->template?->name],
            ['MDA', $submission->mda?->name],
            ['Station', $submission->station?->name ?? 'MDA-level'],
            ['Period', $submission->period?->label()],
            ['Status', $submission->status],
            [],
            ['Section', 'Indicator', 'Dimension', 'Value'],
        ];

        foreach ($submission->template->sections as $section) {
            foreach ($section->indicators as $indicator) {
                $values = $submission->values->where('indicator_code', $indicator->code);
                if ($values->isEmpty()) {
                    $rows[] = [$section->title, $indicator->label, '', ''];
                    continue;
                }

                foreach ($values as $value) {
                    $rows[] = [
                        $section->title,
                        $indicator->label,
                        $value->dimension_value ? "{$value->dimension_key}: {$value->dimension_value}" : '',
                        $value->value_integer ?? $value->value_decimal ?? $value->value_text ?? $value->value_boolean,
                    ];
                }
            }
        }

        $this->auditLogService->logExport('service_reporting.submission', [
            'source' => 'service_reporting',
            'template_id' => $submission->report_template_id,
            'template_code' => $submission->template?->code,
            'submission_id' => $submission->id,
            'mda_id' => $submission->mda_id,
            'station_id' => $submission->station_id,
            'period' => $submission->period?->label(),
            'actor_user_id' => $actor->id,
        ]);

        return Excel::download(new ArrayReportExport($rows), "service-report-submission-{$submission->id}.xlsx");
    }

    public function analytics(array $analytics, User $actor): BinaryFileResponse
    {
        $rows = [];

        foreach ($analytics['indicators'] ?? [$analytics] as $indicatorAnalytics) {
            $rows = [...$rows,
                ['Indicator', $indicatorAnalytics['indicator']['label'] ?? null],
                ['From', $indicatorAnalytics['period_range']['from'] ?? $analytics['period_range']['from'] ?? null],
                ['To', $indicatorAnalytics['period_range']['to'] ?? $analytics['period_range']['to'] ?? null],
                ['Grand Total', $indicatorAnalytics['totals']['grand_total'] ?? 0],
                [],
                ['Monthly Trend'],
                ['Period', 'Value'],
            ];

            foreach ($indicatorAnalytics['series'] ?? [] as $row) {
                $rows[] = [$row['period'], $row['value']];
            }

            $rows[] = [];
            $rows[] = ['Yearly Summary'];
            $rows[] = ['Year', 'Value'];
            foreach ($indicatorAnalytics['by_year'] ?? [] as $row) {
                $rows[] = [$row['year'], $row['value']];
            }

            $rows[] = [];
            $rows[] = ['Facility Comparison'];
            $rows[] = ['Station', 'Value'];
            foreach ($indicatorAnalytics['facility_comparison'] ?? [] as $row) {
                $rows[] = [$row['station_name'], $row['value']];
            }

            $rows[] = [];
        }

        $this->auditLogService->logExport('service_reporting.analytics', [
            'source' => 'service_reporting',
            'actor_user_id' => $actor->id,
        ]);

        return Excel::download(new ArrayReportExport($rows), 'service-report-analytics.xlsx');
    }
}
