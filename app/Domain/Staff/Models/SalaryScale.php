<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryScale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'legacy_id',
        'code',
        'name',
        'min_level',
        'max_level',
        'min_step',
        'max_step',
        'status',
    ];

    public function cadres(): HasMany
    {
        return $this->hasMany(Cadre::class);
    }

    public function ranks(): HasMany
    {
        return $this->hasMany(Rank::class);
    }

    public function qualificationScaleCeilings(): HasMany
    {
        return $this->hasMany(QualificationScaleCeiling::class);
    }

    public function promotionPolicies(): HasMany
    {
        return $this->hasMany(PromotionPolicy::class);
    }

    public function salaryStructureRates(): HasMany
    {
        return $this->hasMany(SalaryStructureRate::class);
    }
}
