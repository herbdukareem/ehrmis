<?php

namespace App\Domain\Budget\Models;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Workplan\Models\WorkplanActivity;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetAllocation extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'budget_cycle_id',
        'budget_account_id',
        'department_id',
        'workplan_activity_id',
        'source_type',
        'source_id',
        'title',
        'description',
        'approved_amount',
        'status',
        'allocated_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function cycle(): BelongsTo { return $this->belongsTo(BudgetCycle::class, 'budget_cycle_id'); }
    public function account(): BelongsTo { return $this->belongsTo(BudgetAccount::class, 'budget_account_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function workplanActivity(): BelongsTo { return $this->belongsTo(WorkplanActivity::class); }
    public function allocatedBy(): BelongsTo { return $this->belongsTo(User::class, 'allocated_by'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function releases(): HasMany { return $this->hasMany(BudgetRelease::class); }
    public function adjustments(): HasMany { return $this->hasMany(BudgetAdjustment::class); }
    public function commitments(): HasMany { return $this->hasMany(BudgetCommitment::class); }
    public function transactions(): HasMany { return $this->hasMany(BudgetTransaction::class); }
}
