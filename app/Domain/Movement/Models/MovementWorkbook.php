<?php

namespace App\Domain\Movement\Models;

use App\Domain\Approval\Models\ApprovalWorkflow;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class MovementWorkbook extends Model
{
    protected $fillable = [
        'mda_id',
        'name',
        'year',
        'budget_year',
        'budget_minimum_step',
        'status',
        'generated_by',
        'reviewed_by',
        'approved_by',
        'generated_at',
        'reviewed_at',
        'approved_at',
        'locked_at',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'budget_year' => 'integer',
            'budget_minimum_step' => 'integer',
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MovementLine::class, 'workbook_id');
    }

    public function summaries(): HasMany
    {
        return $this->hasMany(MovementSummary::class, 'workbook_id');
    }

    public function approvalWorkflow(): MorphOne
    {
        return $this->morphOne(ApprovalWorkflow::class, 'subject');
    }
}
