<?php

namespace App\Domain\ServiceReporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportTemplateSection extends Model
{
    protected $fillable = [
        'report_template_id',
        'title',
        'code',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(ReportTemplateIndicator::class)->orderBy('sort_order')->orderBy('id');
    }
}
