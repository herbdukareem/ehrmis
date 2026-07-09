<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualificationType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'status',
    ];

    public function qualificationScaleCeilings(): HasMany
    {
        return $this->hasMany(QualificationScaleCeiling::class);
    }

    public function scopeUnified(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
