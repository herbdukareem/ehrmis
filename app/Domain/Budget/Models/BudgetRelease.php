<?php

namespace App\Domain\Budget\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetRelease extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'budget_cycle_id',
        'budget_allocation_id',
        'amount',
        'release_date',
        'status',
        'released_by',
        'posted_at',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'release_date' => 'date',
            'posted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function cycle(): BelongsTo { return $this->belongsTo(BudgetCycle::class, 'budget_cycle_id'); }
    public function allocation(): BelongsTo { return $this->belongsTo(BudgetAllocation::class, 'budget_allocation_id'); }
    public function releasedBy(): BelongsTo { return $this->belongsTo(User::class, 'released_by'); }
}
