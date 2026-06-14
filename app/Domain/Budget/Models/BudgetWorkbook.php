<?php

namespace App\Domain\Budget\Models;

use App\Domain\Approval\Models\ApprovalWorkflow;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class BudgetWorkbook extends Model
{
    protected $fillable = [
        'mda_id',
        'movement_workbook_id',
        'year',
        'status',
        'generated_by',
        'approved_by',
        'generated_at',
        'approved_at',
        'locked_at',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
    }

    public function movementWorkbook(): BelongsTo
    {
        return $this->belongsTo(MovementWorkbook::class, 'movement_workbook_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'workbook_id');
    }

    public function approvalWorkflow(): MorphOne
    {
        return $this->morphOne(ApprovalWorkflow::class, 'subject');
    }
}
