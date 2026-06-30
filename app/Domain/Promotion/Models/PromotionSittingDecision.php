<?php

namespace App\Domain\Promotion\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionSittingDecision extends Model
{
    protected $fillable = [
        'sitting_id',
        'application_id',
        'decision',
        'remarks',
        'correction_notes',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function sitting(): BelongsTo
    {
        return $this->belongsTo(PromotionSitting::class, 'sitting_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PromotionApplication::class, 'application_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
