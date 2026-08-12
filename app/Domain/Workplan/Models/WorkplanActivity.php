<?php

namespace App\Domain\Workplan\Models;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkplanActivity extends Model
{
    use HasMdaScope;
    protected $fillable = ['mda_id', 'workplan_objective_id', 'department_id', 'responsible_staff_id', 'activity_code', 'title', 'description', 'expected_output', 'start_date', 'end_date', 'planned_cost', 'funding_source', 'status', 'performance_weight', 'remarks', 'sort_order'];
    protected function casts(): array { return ['start_date' => 'date', 'end_date' => 'date', 'planned_cost' => 'decimal:2', 'performance_weight' => 'decimal:4']; }
    public function objective(): BelongsTo { return $this->belongsTo(WorkplanObjective::class, 'workplan_objective_id'); }
    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function responsibleStaff(): BelongsTo { return $this->belongsTo(Staff::class, 'responsible_staff_id'); }
    public function supportAssignments(): HasMany { return $this->hasMany(WorkplanActivityAssignment::class)->where('role', 'support'); }
    public function indicators(): HasMany { return $this->hasMany(WorkplanIndicator::class)->orderBy('sort_order')->orderBy('id'); }
    public function progressReports(): HasMany { return $this->hasMany(WorkplanProgressReport::class); }
}
