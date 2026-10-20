<?php

namespace App\Domain\Budget\Models;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetTransaction extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'budget_cycle_id',
        'budget_allocation_id',
        'budget_account_id',
        'department_id',
        'transaction_type',
        'amount',
        'signed_amount',
        'transaction_date',
        'source_type',
        'source_id',
        'description',
        'recorded_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'signed_amount' => 'decimal:2',
            'transaction_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function cycle(): BelongsTo { return $this->belongsTo(BudgetCycle::class, 'budget_cycle_id'); }
    public function allocation(): BelongsTo { return $this->belongsTo(BudgetAllocation::class, 'budget_allocation_id'); }
    public function account(): BelongsTo { return $this->belongsTo(BudgetAccount::class, 'budget_account_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
