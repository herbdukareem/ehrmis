<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffSalaryPlacement extends Model
{
    protected $fillable = [
        'staff_id',
        'salary_scale_id',
        'level',
        'step',
        'basic_salary',
        'gross_salary',
        'basic_salary_snapshot',
        'allowance_total_snapshot',
        'allowance_breakdown_snapshot',
        'legacy_gross_salary_snapshot',
        'calculated_gross_salary_snapshot',
        'gross_difference_snapshot',
        'source',
        'is_current',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'step' => 'integer',
            'basic_salary' => 'decimal:2',
            'gross_salary' => 'decimal:2',
            'basic_salary_snapshot' => 'decimal:2',
            'allowance_total_snapshot' => 'decimal:2',
            'allowance_breakdown_snapshot' => 'array',
            'legacy_gross_salary_snapshot' => 'decimal:2',
            'calculated_gross_salary_snapshot' => 'decimal:2',
            'gross_difference_snapshot' => 'decimal:2',
            'is_current' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function salaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class);
    }
}
