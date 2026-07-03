<?php

namespace App\Domain\ServiceReporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportTemplateDimension extends Model
{
    protected $fillable = [
        'report_template_indicator_id',
        'dimension_key',
        'dimension_label',
        'dimension_values',
        'is_required',
        'total_strategy',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'dimension_values' => 'array',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(ReportTemplateIndicator::class, 'report_template_indicator_id');
    }
}
