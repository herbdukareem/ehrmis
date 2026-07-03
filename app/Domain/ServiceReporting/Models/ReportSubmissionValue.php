<?php

namespace App\Domain\ServiceReporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSubmissionValue extends Model
{
    protected $fillable = [
        'report_submission_id',
        'report_template_indicator_id',
        'indicator_code',
        'dimension_key',
        'dimension_value',
        'value_integer',
        'value_decimal',
        'value_text',
        'value_boolean',
        'computed_value_decimal',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'value_integer' => 'integer',
            'value_decimal' => 'decimal:4',
            'value_boolean' => 'boolean',
            'computed_value_decimal' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ReportSubmission::class, 'report_submission_id');
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(ReportTemplateIndicator::class, 'report_template_indicator_id');
    }
}
