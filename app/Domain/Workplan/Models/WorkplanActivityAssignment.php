<?php

namespace App\Domain\Workplan\Models;

use App\Domain\Staff\Models\Staff;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkplanActivityAssignment extends Model
{
    use HasMdaScope;
    protected $fillable = ['mda_id', 'workplan_activity_id', 'staff_id', 'role'];
    public function activity(): BelongsTo { return $this->belongsTo(WorkplanActivity::class, 'workplan_activity_id'); }
    public function staff(): BelongsTo { return $this->belongsTo(Staff::class); }
}
