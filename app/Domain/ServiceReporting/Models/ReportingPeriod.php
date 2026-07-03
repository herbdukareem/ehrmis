<?php

namespace App\Domain\ServiceReporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportingPeriod extends Model
{
    protected $fillable = [
        'frequency',
        'period_year',
        'period_month',
        'period_quarter',
        'start_date',
        'end_date',
        'submission_due_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'period_quarter' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'submission_due_date' => 'date',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ReportSubmission::class);
    }

    public function label(): string
    {
        return match ($this->frequency) {
            'quarterly' => sprintf('%d Q%d', $this->period_year, $this->period_quarter),
            'yearly' => (string) $this->period_year,
            default => sprintf('%04d-%02d', $this->period_year, $this->period_month),
        };
    }
}
