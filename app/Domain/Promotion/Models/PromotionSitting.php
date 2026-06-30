<?php

namespace App\Domain\Promotion\Models;

use App\Domain\Approval\Models\ApprovalWorkflow;
use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class PromotionSitting extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'cycle_id',
        'mda_id',
        'title',
        'sitting_date',
        'panel_notes',
        'status',
        'created_by',
        'completed_by',
        'completed_at',
        'print_authorized_by',
        'print_authorized_at',
    ];

    protected function casts(): array
    {
        return [
            'sitting_date' => 'date',
            'completed_at' => 'datetime',
            'print_authorized_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PromotionCycle::class, 'cycle_id');
    }

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PromotionApplication::class, 'sitting_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(PromotionSittingDecision::class, 'sitting_id');
    }

    public function approvalWorkflow(): MorphOne
    {
        return $this->morphOne(ApprovalWorkflow::class, 'subject');
    }
}
