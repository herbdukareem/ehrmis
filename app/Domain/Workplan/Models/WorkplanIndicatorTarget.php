<?php

namespace App\Domain\Workplan\Models;

use App\Enums\WorkplanTargetPeriod;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkplanIndicatorTarget extends Model
{
    use HasMdaScope;
    protected $fillable = ['mda_id', 'workplan_indicator_id', 'period', 'target_value'];
    protected function casts(): array { return ['period' => WorkplanTargetPeriod::class, 'target_value' => 'decimal:4']; }
    public function indicator(): BelongsTo { return $this->belongsTo(WorkplanIndicator::class, 'workplan_indicator_id'); }
}
