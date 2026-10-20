<?php

namespace App\Domain\Budget\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetCommitment extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'budget_cycle_id',
        'budget_allocation_id',
        'commitment_no',
        'source_type',
        'source_id',
        'description',
        'amount',
        'status',
        'committed_by',
        'committed_at',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'committed_at' => 'datetime',
            'expires_at' => 'date',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function cycle(): BelongsTo { return $this->belongsTo(BudgetCycle::class, 'budget_cycle_id'); }
    public function allocation(): BelongsTo { return $this->belongsTo(BudgetAllocation::class, 'budget_allocation_id'); }
    public function committedBy(): BelongsTo { return $this->belongsTo(User::class, 'committed_by'); }
    public function transactions(): HasMany { return $this->hasMany(BudgetTransaction::class, 'source_id')->where('source_type', self::class); }
}
