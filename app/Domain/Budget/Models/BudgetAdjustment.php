<?php

namespace App\Domain\Budget\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetAdjustment extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'budget_cycle_id',
        'budget_allocation_id',
        'adjustment_type',
        'amount',
        'status',
        'reason',
        'approved_by',
        'approved_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function signedAmount(): float
    {
        $amount = (float) $this->amount;

        return in_array($this->adjustment_type, ['decrease', 'transfer_out', 'reduction'], true)
            ? -1 * $amount
            : $amount;
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function cycle(): BelongsTo { return $this->belongsTo(BudgetCycle::class, 'budget_cycle_id'); }
    public function allocation(): BelongsTo { return $this->belongsTo(BudgetAllocation::class, 'budget_allocation_id'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
