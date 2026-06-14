<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryStructureRate extends Model
{
    protected $fillable = [
        'salary_scale_id',
        'level',
        'step',
        'basic_salary',
        'legacy_gross_salary',
        'status',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'legacy_gross_salary' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function salaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class);
    }

    public function rateAllowances(): HasMany
    {
        return $this->hasMany(SalaryStructureRateAllowance::class);
    }

    public function allowanceTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            AllowanceType::class,
            'salary_structure_rate_allowances',
            'salary_structure_rate_id',
            'allowance_type_id'
        )->withPivot(['amount', 'status'])->withTimestamps();
    }
}
