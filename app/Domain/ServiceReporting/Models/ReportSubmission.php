<?php

namespace App\Domain\ServiceReporting\Models;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReportSubmission extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'report_template_id',
        'reporting_period_id',
        'mda_id',
        'station_id',
        'department_id',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'locked_by',
        'locked_at',
        'returned_by',
        'returned_at',
        'status',
        'return_reason',
        'summary',
        'is_late',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
            'returned_at' => 'datetime',
            'summary' => 'array',
            'is_late' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ReportSubmissionValue::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ReportSubmissionReview::class)->orderBy('acted_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReportSubmissionAttachment::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function canEditValues(): bool
    {
        return in_array($this->status, ['draft', 'returned'], true);
    }
}
