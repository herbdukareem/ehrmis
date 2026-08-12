<?php

namespace App\Domain\Workplan\Models;

use App\Enums\WorkplanIndicatorDirection;
use App\Enums\WorkplanTargetMode;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkplanIndicator extends Model
{
    use HasMdaScope;
    protected $fillable = ['mda_id', 'workplan_activity_id', 'code', 'indicator', 'unit', 'baseline_value', 'annual_target_value', 'target_mode', 'direction', 'weight', 'sort_order', 'is_required'];
    protected function casts(): array { return ['target_mode' => WorkplanTargetMode::class, 'direction' => WorkplanIndicatorDirection::class, 'baseline_value' => 'decimal:4', 'annual_target_value' => 'decimal:4', 'weight' => 'decimal:4', 'is_required' => 'boolean']; }
    public function activity(): BelongsTo { return $this->belongsTo(WorkplanActivity::class, 'workplan_activity_id'); }
    public function targets(): HasMany { return $this->hasMany(WorkplanIndicatorTarget::class)->orderByRaw("CASE period WHEN 'q1' THEN 1 WHEN 'q2' THEN 2 WHEN 'q3' THEN 3 WHEN 'q4' THEN 4 WHEN 'annual' THEN 5 END"); }
}
