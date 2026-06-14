<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAllowanceAssignment extends Model
{
    protected $fillable = [
        'staff_id',
        'allowance_type_id',
        'is_eligible',
        'source',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'is_eligible' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function allowanceType(): BelongsTo
    {
        return $this->belongsTo(AllowanceType::class);
    }
}
