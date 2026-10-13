<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Workplan\Models\WorkplanActivity;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementPlanLine extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'procurement_plan_id',
        'department_id',
        'budget_allocation_id',
        'workplan_activity_id',
        'source_type',
        'source_id',
        'item_description',
        'specification',
        'quantity',
        'unit',
        'estimated_unit_cost',
        'estimated_amount',
        'procurement_method',
        'planned_start_date',
        'planned_end_date',
        'status',
        'priority',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'estimated_unit_cost' => 'decimal:2',
            'estimated_amount' => 'decimal:2',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function plan(): BelongsTo { return $this->belongsTo(ProcurementPlan::class, 'procurement_plan_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function budgetAllocation(): BelongsTo { return $this->belongsTo(BudgetAllocation::class); }
    public function workplanActivity(): BelongsTo { return $this->belongsTo(WorkplanActivity::class); }
    public function requisitionLines(): HasMany { return $this->hasMany(ProcurementRequisitionLine::class); }
}
