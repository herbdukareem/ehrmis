<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementRequisition extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'procurement_plan_id',
        'budget_cycle_id',
        'department_id',
        'requisition_no',
        'title',
        'description',
        'justification',
        'total_amount',
        'status',
        'budget_review_status',
        'requested_by',
        'submitted_by',
        'submitted_at',
        'budget_reviewed_by',
        'budget_reviewed_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'budget_reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function plan(): BelongsTo { return $this->belongsTo(ProcurementPlan::class, 'procurement_plan_id'); }
    public function budgetCycle(): BelongsTo { return $this->belongsTo(BudgetCycle::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function requestedBy(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function budgetReviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'budget_reviewed_by'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejectedBy(): BelongsTo { return $this->belongsTo(User::class, 'rejected_by'); }
    public function lines(): HasMany { return $this->hasMany(ProcurementRequisitionLine::class); }
    public function solicitations(): HasMany { return $this->hasMany(ProcurementSolicitation::class); }
    public function purchaseOrders(): HasMany { return $this->hasMany(PurchaseOrder::class); }
}
