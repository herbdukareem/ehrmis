<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionPolicy extends Model
{
    protected $fillable = [
        'salary_scale_id',
        'min_level',
        'max_level',
        'required_years',
        'policy_type',
        'description',
        'status',
    ];

    public function salaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class);
    }
}
