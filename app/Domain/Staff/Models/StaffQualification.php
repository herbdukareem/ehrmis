<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffQualification extends Model
{
    protected $fillable = [
        'staff_id',
        'qualification_type_id',
        'qualification_name',
        'highest_qualification_name',
        'specialization',
        'is_highest',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'is_highest' => 'boolean',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function qualificationType(): BelongsTo
    {
        return $this->belongsTo(QualificationType::class);
    }
}
