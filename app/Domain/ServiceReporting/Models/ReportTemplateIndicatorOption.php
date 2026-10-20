<?php

namespace App\Domain\ServiceReporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportTemplateIndicatorOption extends Model
{
    protected $fillable = [
        'report_template_indicator_id',
        'label',
        'value',
        'score',
        'requires_review',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'requires_review' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(ReportTemplateIndicator::class, 'report_template_indicator_id');
    }
}
