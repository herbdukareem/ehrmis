<?php

namespace App\Domain\Budget\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetCycle extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'year',
        'name',
        'status',
        'created_by',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'opened_at',
        'locked_at',
        'closed_at',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'opened_at' => 'datetime',
            'locked_at' => 'datetime',
            'closed_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function allocations(): HasMany { return $this->hasMany(BudgetAllocation::class); }
    public function releases(): HasMany { return $this->hasMany(BudgetRelease::class); }
    public function adjustments(): HasMany { return $this->hasMany(BudgetAdjustment::class); }
    public function commitments(): HasMany { return $this->hasMany(BudgetCommitment::class); }
    public function transactions(): HasMany { return $this->hasMany(BudgetTransaction::class); }
}
