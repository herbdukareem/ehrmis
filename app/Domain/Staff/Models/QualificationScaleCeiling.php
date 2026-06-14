<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualificationScaleCeiling extends Model
{
    protected $fillable = [
        'qualification_type_id',
        'salary_scale_id',
        'max_level',
        'status',
    ];

    public function qualificationType(): BelongsTo
    {
        return $this->belongsTo(QualificationType::class);
    }

    public function salaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class);
    }
}
