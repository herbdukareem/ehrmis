<?php

namespace App\Domain\ServiceReporting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportTemplateIndicator extends Model
{
    protected $fillable = [
        'report_template_section_id',
        'code',
        'label',
        'description',
        'placeholder',
        'help_text',
        'value_type',
        'field_type',
        'unit',
        'default_value',
        'is_required',
        'is_computed',
        'is_read_only',
        'contributes_to_score',
        'compute_formula',
        'validation_rules',
        'display_rules',
        'required_rules',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_computed' => 'boolean',
            'is_read_only' => 'boolean',
            'contributes_to_score' => 'boolean',
            'compute_formula' => 'array',
            'validation_rules' => 'array',
            'display_rules' => 'array',
            'required_rules' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ReportTemplateSection::class, 'report_template_section_id');
    }

    public function dimensions(): HasMany
    {
        return $this->hasMany(ReportTemplateDimension::class)->orderBy('sort_order')->orderBy('id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ReportTemplateIndicatorOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ReportSubmissionValue::class, 'report_template_indicator_id');
    }
}
