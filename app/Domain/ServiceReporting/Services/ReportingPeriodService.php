<?php

namespace App\Domain\ServiceReporting\Services;

use App\Domain\ServiceReporting\Models\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ReportingPeriodService
{
    public function getOrCreate(string $frequency, int $year, ?int $month = null, ?int $quarter = null, ?int $deadlineDay = null): ReportingPeriod
    {
        [$start, $end] = $this->bounds($frequency, $year, $month, $quarter);
        $dueDate = $deadlineDay ? $this->dueDate($frequency, $year, $month, $quarter, $deadlineDay) : null;

        return ReportingPeriod::query()->firstOrCreate(
            [
                'frequency' => $frequency,
                'period_year' => $year,
                'period_month' => $frequency === 'monthly' ? $month : null,
                'period_quarter' => $frequency === 'quarterly' ? $quarter : null,
            ],
            [
                'start_date' => $start,
                'end_date' => $end,
                'submission_due_date' => $dueDate,
                'status' => 'open',
            ],
        );
    }

    public function fromPeriodString(string $frequency, string $period, ?int $deadlineDay = null): ReportingPeriod
    {
        if ($frequency === 'monthly') {
            if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
                throw ValidationException::withMessages(['period' => 'Monthly periods must use YYYY-MM format.']);
            }

            [$year, $month] = array_map('intval', explode('-', $period));

            return $this->getOrCreate($frequency, $year, $month, null, $deadlineDay);
        }

        if ($frequency === 'quarterly') {
            if (! preg_match('/^\d{4}-Q[1-4]$/', $period)) {
                throw ValidationException::withMessages(['period' => 'Quarterly periods must use YYYY-QN format.']);
            }

            return $this->getOrCreate($frequency, (int) substr($period, 0, 4), null, (int) substr($period, -1), $deadlineDay);
        }

        return $this->getOrCreate($frequency, (int) $period, null, null, $deadlineDay);
    }

    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    protected function bounds(string $frequency, int $year, ?int $month, ?int $quarter): array
    {
        return match ($frequency) {
            'yearly' => [
                CarbonImmutable::create($year, 1, 1)->startOfDay(),
                CarbonImmutable::create($year, 12, 31)->startOfDay(),
            ],
            'quarterly' => [
                CarbonImmutable::create($year, (($quarter ?: 1) - 1) * 3 + 1, 1)->startOfDay(),
                CarbonImmutable::create($year, (($quarter ?: 1) - 1) * 3 + 1, 1)->addMonths(2)->endOfMonth()->startOfDay(),
            ],
            default => [
                CarbonImmutable::create($year, $month ?: 1, 1)->startOfDay(),
                CarbonImmutable::create($year, $month ?: 1, 1)->endOfMonth()->startOfDay(),
            ],
        };
    }

    protected function dueDate(string $frequency, int $year, ?int $month, ?int $quarter, int $deadlineDay): Carbon
    {
        [$start, $end] = $this->bounds($frequency, $year, $month, $quarter);

        return Carbon::parse($end)->addDay()->day(min($deadlineDay, 28));
    }
}
