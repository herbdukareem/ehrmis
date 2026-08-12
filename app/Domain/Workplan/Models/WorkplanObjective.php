<?php

namespace App\Domain\Workplan\Models;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkplanObjective extends Model
{
    use HasMdaScope;
    protected $fillable = ['mda_id', 'workplan_id', 'department_id', 'code', 'title', 'description', 'priority', 'performance_weight', 'sort_order'];
    public function workplan(): BelongsTo { return $this->belongsTo(Workplan::class); }
    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function activities(): HasMany { return $this->hasMany(WorkplanActivity::class)->orderBy('sort_order')->orderBy('id'); }
}
