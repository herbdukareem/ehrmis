<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AllowanceType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'status',
    ];

    public function salaryStructureRateAllowances(): HasMany
    {
        return $this->hasMany(SalaryStructureRateAllowance::class);
    }

    public function staffAllowanceAssignments(): HasMany
    {
        return $this->hasMany(StaffAllowanceAssignment::class);
    }
}
