<?php

namespace App\Domain\Promotion\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionCycle extends Model
{
    protected $fillable = [
        'mda_id',
        'title',
        'year',
        'opens_at',
        'closes_at',
        'status',
        'created_by',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'opens_at' => 'date',
            'closes_at' => 'date',
            'summary' => 'array',
        ];
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
        return $this->hasMany(PromotionApplication::class, 'cycle_id');
    }

    public function sittings(): HasMany
    {
        return $this->hasMany(PromotionSitting::class, 'cycle_id');
    }
}
