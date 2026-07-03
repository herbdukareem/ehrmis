<?php

namespace App\Domain\ServiceReporting\Services;

use App\Domain\ServiceReporting\Models\ReportSubmissionValue;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Domain\ServiceReporting\Models\ReportTemplateIndicator;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportAnalyticsService
{
    public function trend(array $filters, User $user): array
    {
        $template = ReportTemplate::query()->where('code', $filters['template_code'])->firstOrFail();
        $indicator = ReportTemplateIndicator::query()
            ->where('code', $filters['indicator_code'])
            ->whereHas('section', fn ($query) => $query->where('report_template_id', $template->id))
            ->firstOrFail();

        $rows = $this->valueQuery($filters, $user, $template, $indicator)
            ->selectRaw('reporting_periods.period_year, reporting_periods.period_month, report_submissions.station_id, stations.name as station_name, SUM(COALESCE(value_integer, value_decimal, computed_value_decimal, 0)) as aggregate_value')
            ->groupBy('reporting_periods.period_year', 'reporting_periods.period_month', 'report_submissions.station_id', 'stations.name')
            ->orderBy('reporting_periods.period_year')
            ->orderBy('reporting_periods.period_month')
            ->get();

        $series = $rows
            ->groupBy(fn ($row): string => sprintf('%04d-%02d', $row->period_year, $row->period_month))
            ->map(fn (Collection $periodRows, string $period): array => [
                'period' => $period,
                'value' => (float) $periodRows->sum('aggregate_value'),
            ])
            ->values();

        return [
            'indicator' => [
                'id' => $indicator->id,
                'code' => $indicator->code,
                'label' => $indicator->label,
                'value_type' => $indicator->value_type,
            ],
            'period_range' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'totals' => [
                'grand_total' => (float) $rows->sum('aggregate_value'),
            ],
            'series' => $series,
            'by_year' => $rows
                ->groupBy('period_year')
                ->map(fn (Collection $yearRows, int $year): array => ['year' => $year, 'value' => (float) $yearRows->sum('aggregate_value')])
                ->values(),
            'facility_comparison' => $rows
                ->filter(fn ($row): bool => $row->station_id !== null)
                ->groupBy('station_id')
                ->map(fn (Collection $stationRows): array => [
                    'station_id' => (int) $stationRows->first()->station_id,
                    'station_name' => $stationRows->first()->station_name,
                    'value' => (float) $stationRows->sum('aggregate_value'),
                ])
                ->values(),
        ];
    }

    public function compliance(array $filters, User $user): array
    {
        $query = ReportTemplate::query()
            ->active()
            ->withCount(['assignments as expected_submissions' => function ($query) use ($filters, $user): void {
                $query->active();
                if (! $user->hasGlobalMdaAccess()) {
                    $query->whereIn('mda_id', $user->accessibleMdaIds()->all());
                }
                if (! empty($filters['mda_id'])) {
                    $query->where('mda_id', $filters['mda_id']);
                }
            }])
            ->withCount([
                'submissions as submitted_count' => fn ($query) => $this->scopeSubmissionStatus($query, ['submitted', 'under_review', 'approved', 'locked'], $filters, $user),
                'submissions as approved_count' => fn ($query) => $this->scopeSubmissionStatus($query, ['approved'], $filters, $user),
                'submissions as locked_count' => fn ($query) => $this->scopeSubmissionStatus($query, ['locked'], $filters, $user),
                'submissions as returned_count' => fn ($query) => $this->scopeSubmissionStatus($query, ['returned'], $filters, $user),
            ]);

        if (! empty($filters['template_code'])) {
            $query->where('code', $filters['template_code']);
        }

        return $query->get()->map(fn (ReportTemplate $template): array => [
            'template_id' => $template->id,
            'template_code' => $template->code,
            'template_name' => $template->name,
            'expected' => $template->expected_submissions,
            'submitted' => $template->submitted_count,
            'approved' => $template->approved_count,
            'locked' => $template->locked_count,
            'returned' => $template->returned_count,
            'missing' => max(0, $template->expected_submissions - $template->submitted_count),
        ])->values()->all();
    }

    protected function valueQuery(array $filters, User $user, ReportTemplate $template, ReportTemplateIndicator $indicator): Builder
    {
        return ReportSubmissionValue::query()
            ->join('report_submissions', 'report_submission_values.report_submission_id', '=', 'report_submissions.id')
            ->join('reporting_periods', 'report_submissions.reporting_period_id', '=', 'reporting_periods.id')
            ->leftJoin('stations', 'report_submissions.station_id', '=', 'stations.id')
            ->where('report_submissions.report_template_id', $template->id)
            ->where('report_submission_values.report_template_indicator_id', $indicator->id)
            ->when(! $user->hasGlobalMdaAccess(), fn ($query) => $query->whereIn('report_submissions.mda_id', $user->accessibleMdaIds()->all()))
            ->when(! empty($filters['mda_id']), fn ($query) => $query->where('report_submissions.mda_id', $filters['mda_id']))
            ->when(! empty($filters['station_id']), fn ($query) => $query->where('report_submissions.station_id', $filters['station_id']))
            ->when(! empty($filters['status']), fn ($query) => $query->whereIn('report_submissions.status', is_array($filters['status']) ? $filters['status'] : explode(',', $filters['status'])))
            ->when(! empty($filters['from']), function ($query) use ($filters): void {
                [$year, $month] = array_map('intval', explode('-', $filters['from']));
                $query->where(function ($periodQuery) use ($year, $month): void {
                    $periodQuery
                        ->where('reporting_periods.period_year', '>', $year)
                        ->orWhere(function ($sameYear) use ($year, $month): void {
                            $sameYear
                                ->where('reporting_periods.period_year', $year)
                                ->where('reporting_periods.period_month', '>=', $month);
                        });
                });
            })
            ->when(! empty($filters['to']), function ($query) use ($filters): void {
                [$year, $month] = array_map('intval', explode('-', $filters['to']));
                $query->where(function ($periodQuery) use ($year, $month): void {
                    $periodQuery
                        ->where('reporting_periods.period_year', '<', $year)
                        ->orWhere(function ($sameYear) use ($year, $month): void {
                            $sameYear
                                ->where('reporting_periods.period_year', $year)
                                ->where('reporting_periods.period_month', '<=', $month);
                        });
                });
            });
    }

    protected function scopeSubmissionStatus($query, array $statuses, array $filters, User $user)
    {
        return $query
            ->whereIn('status', $statuses)
            ->when(! $user->hasGlobalMdaAccess(), fn ($submissions) => $submissions->whereIn('mda_id', $user->accessibleMdaIds()->all()))
            ->when(! empty($filters['mda_id']), fn ($submissions) => $submissions->where('mda_id', $filters['mda_id']));
    }
}
