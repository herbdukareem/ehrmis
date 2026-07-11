<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructureRateAllowance extends Model
{
    protected $fillable = [
        'salary_structure_rate_id',
        'allowance_type_id',
        'amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function salaryStructureRate(): BelongsTo
    {
        return $this->belongsTo(SalaryStructureRate::class);
    }

    public function allowanceType(): BelongsTo
    {
        return $this->belongsTo(AllowanceType::class);
    }
}
