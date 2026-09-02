<?php

namespace App\Domain\ServiceReporting\Services;

use App\Domain\ServiceReporting\Models\ReportSubmissionValue;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Domain\ServiceReporting\Models\ReportTemplateIndicator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportAnalyticsService
{
    public function trend(array $filters, User $user): array
    {
        $user->loadMissing('station');
        $template = ReportTemplate::query()->where('code', $filters['template_code'])->firstOrFail();
        $indicatorCodes = collect($filters['indicator_codes'] ?? [$filters['indicator_code']])
            ->filter()
            ->unique()
            ->values();
        $indicators = ReportTemplateIndicator::query()
            ->whereIn('code', $indicatorCodes)
            ->whereHas('section', fn ($query) => $query->where('report_template_id', $template->id))
            ->get()
            ->keyBy('code');

        abort_unless($indicators->count() === $indicatorCodes->count(), 404);

        $analytics = $indicatorCodes
            ->map(fn (string $code): array => $this->trendForIndicator($filters, $user, $template, $indicators->get($code)))
            ->values();

        if ($analytics->count() === 1) {
            return $analytics->first();
        }

        return [
            'indicators' => $analytics,
            'period_range' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
        ];
    }

    protected function trendForIndicator(array $filters, User $user, ReportTemplate $template, ReportTemplateIndicator $indicator): array
    {
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
        $user->loadMissing('station');

        $query = ReportTemplate::query()
            ->active()
            ->withCount(['assignments as expected_submissions' => function ($query) use ($filters, $user): void {
                $query->active();
                if ($user->hasStationScope()) {
                    if (! $user->station) {
                        $query->whereRaw('1 = 0');
                        return;
                    }

                    $query
                        ->where('mda_id', $user->station->mda_id)
                        ->where(function ($stationQuery) use ($user): void {
                            $stationQuery
                                ->whereNull('station_id')
                                ->orWhere('station_id', $user->station_id);
                        });
                } elseif (! $user->hasGlobalMdaAccess()) {
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

    public function templateTable(array $filters, User $user): array
    {
        $user->loadMissing('station');
        $template = ReportTemplate::query()
            ->where('code', $filters['template_code'])
            ->with('sections.indicators.dimensions')
            ->firstOrFail();

        $values = $this->templateValueQuery($filters, $user, $template)
            ->select([
                'report_submission_values.report_template_indicator_id',
                'report_submission_values.dimension_key',
                'report_submission_values.dimension_value',
                'report_submission_values.value_integer',
                'report_submission_values.value_decimal',
                'report_submission_values.computed_value_decimal',
                'report_submission_values.value_text',
                'report_submission_values.value_boolean',
                'reporting_periods.period_year',
                'reporting_periods.period_month',
            ])
            ->orderBy('reporting_periods.period_year')
            ->orderBy('reporting_periods.period_month')
            ->get();

        $periods = $this->tablePeriods($filters, $values);
        $periodKeys = collect($periods)->pluck('key')->all();
        $valuesByRow = $values->groupBy(fn ($value): string => implode('|', [
            $value->report_template_indicator_id,
            $value->dimension_key ?? '',
            $value->dimension_value ?? '',
        ]));

        return [
            'template' => $template->only(['id', 'code', 'name']),
            'periods' => $periods,
            'sections' => $template->sections->map(function ($section) use ($periodKeys, $valuesByRow): array {
                return [
                    'id' => $section->id,
                    'title' => $section->title,
                    'description' => $section->description,
                    'indicators' => $section->indicators->map(function (ReportTemplateIndicator $indicator) use ($periodKeys, $valuesByRow): array {
                        $dimensionRows = $indicator->dimensions->flatMap(fn ($dimension) => collect($dimension->dimension_values)->map(fn ($value): array => [
                            'dimension_key' => $dimension->dimension_key,
                            'dimension_label' => "{$dimension->dimension_label}: {$value}",
                            'dimension_value' => $value,
                        ]));
                        $dimensionRows = $dimensionRows->isNotEmpty() ? $dimensionRows : collect([[
                            'dimension_key' => null,
                            'dimension_label' => null,
                            'dimension_value' => null,
                        ]]);

                        return [
                            'id' => $indicator->id,
                            'label' => $indicator->label,
                            'unit' => $indicator->unit,
                            'rows' => $dimensionRows->map(function (array $row) use ($indicator, $periodKeys, $valuesByRow): array {
                                $key = implode('|', [$indicator->id, $row['dimension_key'] ?? '', $row['dimension_value'] ?? '']);
                                $byPeriod = $valuesByRow->get($key, collect())->groupBy(fn ($value): string => sprintf('%04d-%02d', $value->period_year, $value->period_month));

                                return [
                                    ...$row,
                                    'values' => collect($periodKeys)->mapWithKeys(fn (string $period): array => [$period => $this->tableValue($byPeriod->get($period, collect()), $indicator->value_type)])->all(),
                                ];
                            })->values(),
                        ];
                    })->values(),
                ];
            })->values(),
        ];
    }

    protected function valueQuery(array $filters, User $user, ReportTemplate $template, ReportTemplateIndicator $indicator): Builder
    {
        return $this->templateValueQuery($filters, $user, $template)
            ->where('report_submission_values.report_template_indicator_id', $indicator->id);
    }

    protected function templateValueQuery(array $filters, User $user, ReportTemplate $template): Builder
    {
        return ReportSubmissionValue::query()
            ->join('report_submissions', 'report_submission_values.report_submission_id', '=', 'report_submissions.id')
            ->join('reporting_periods', 'report_submissions.reporting_period_id', '=', 'reporting_periods.id')
            ->leftJoin('stations', 'report_submissions.station_id', '=', 'stations.id')
            ->where('report_submissions.report_template_id', $template->id)
            ->when($user->hasStationScope(), function ($query) use ($user): void {
                if (! $user->station) {
                    $query->whereRaw('1 = 0');
                    return;
                }

                $query
                    ->where('report_submissions.mda_id', $user->station->mda_id)
                    ->where('report_submissions.station_id', $user->station_id);
            }, function ($query) use ($user): void {
                if (! $user->hasGlobalMdaAccess()) {
                    $query->whereIn('report_submissions.mda_id', $user->accessibleMdaIds()->all());
                }
            })
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

    protected function tablePeriods(array $filters, Collection $values): array
    {
        if (! empty($filters['from']) && ! empty($filters['to'])) {
            $cursor = CarbonImmutable::createFromFormat('Y-m', $filters['from'])->startOfMonth();
            $end = CarbonImmutable::createFromFormat('Y-m', $filters['to'])->startOfMonth();
            $periods = [];

            while ($cursor->lessThanOrEqualTo($end)) {
                $periods[] = ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M Y')];
                $cursor = $cursor->addMonth();
            }

            return $periods;
        }

        return $values->map(fn ($value): array => [
            'key' => sprintf('%04d-%02d', $value->period_year, $value->period_month),
            'label' => CarbonImmutable::create($value->period_year, $value->period_month, 1)->format('M Y'),
        ])->unique('key')->values()->all();
    }

    protected function tableValue(Collection $values, string $valueType): string|int|float|null
    {
        if ($values->isEmpty()) {
            return null;
        }

        if (in_array($valueType, ['integer', 'decimal', 'percentage'], true)) {
            return (float) $values->sum(fn ($value): float => (float) ($value->value_integer ?? $value->value_decimal ?? $value->computed_value_decimal ?? 0));
        }

        if ($valueType === 'boolean') {
            $states = $values->pluck('value_boolean')->filter(fn ($value) => $value !== null)->unique();
            return $states->count() === 1 ? ($states->first() ? 'Yes' : 'No') : 'Mixed';
        }

        return $values->pluck('value_text')->filter(fn ($value) => $value !== null && $value !== '')->unique()->implode(' · ') ?: null;
    }

    protected function scopeSubmissionStatus($query, array $statuses, array $filters, User $user)
    {
        return $query
            ->whereIn('status', $statuses)
            ->when($user->hasStationScope(), function ($submissions) use ($user): void {
                if (! $user->station) {
                    $submissions->whereRaw('1 = 0');
                    return;
                }

                $submissions
                    ->where('mda_id', $user->station->mda_id)
                    ->where('station_id', $user->station_id);
            }, function ($submissions) use ($user): void {
                if (! $user->hasGlobalMdaAccess()) {
                    $submissions->whereIn('mda_id', $user->accessibleMdaIds()->all());
                }
            })
            ->when(! empty($filters['mda_id']), fn ($submissions) => $submissions->where('mda_id', $filters['mda_id']));
    }
}
