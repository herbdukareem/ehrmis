<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCommitment;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementRequisitionLine extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'procurement_requisition_id',
        'procurement_plan_line_id',
        'budget_allocation_id',
        'budget_commitment_id',
        'department_id',
        'item_description',
        'specification',
        'quantity',
        'unit',
        'estimated_unit_cost',
        'estimated_amount',
        'status',
        'budget_check_status',
        'budget_check_summary',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'estimated_unit_cost' => 'decimal:2',
            'estimated_amount' => 'decimal:2',
            'budget_check_summary' => 'array',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function requisition(): BelongsTo { return $this->belongsTo(ProcurementRequisition::class, 'procurement_requisition_id'); }
    public function planLine(): BelongsTo { return $this->belongsTo(ProcurementPlanLine::class, 'procurement_plan_line_id'); }
    public function budgetAllocation(): BelongsTo { return $this->belongsTo(BudgetAllocation::class); }
    public function budgetCommitment(): BelongsTo { return $this->belongsTo(BudgetCommitment::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
}
