<?php

namespace App\Domain\Staff\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructureRateAllowance extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
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

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
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
