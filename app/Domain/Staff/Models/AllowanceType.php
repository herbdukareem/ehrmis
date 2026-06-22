<?php

namespace App\Domain\Staff\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AllowanceType extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'code',
        'name',
        'description',
        'status',
    ];

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
    }

    public function salaryStructureRateAllowances(): HasMany
    {
        return $this->hasMany(SalaryStructureRateAllowance::class);
    }

    public function staffAllowanceAssignments(): HasMany
    {
        return $this->hasMany(StaffAllowanceAssignment::class);
    }
}
