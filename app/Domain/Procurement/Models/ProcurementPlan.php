<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementPlan extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'budget_cycle_id',
        'year',
        'title',
        'description',
        'status',
        'source_type',
        'prepared_by',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function budgetCycle(): BelongsTo { return $this->belongsTo(BudgetCycle::class); }
    public function preparedBy(): BelongsTo { return $this->belongsTo(User::class, 'prepared_by'); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function lines(): HasMany { return $this->hasMany(ProcurementPlanLine::class); }
    public function requisitions(): HasMany { return $this->hasMany(ProcurementRequisition::class); }
}
