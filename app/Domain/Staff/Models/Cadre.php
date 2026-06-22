<?php

namespace App\Domain\Staff\Models;

use App\Domain\Organization\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cadre extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'legacy_id',
        'salary_scale_id',
        'department_id',
        'name',
        'legacy_department_name',
        'description',
        'status',
    ];

    public function salaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function ranks(): HasMany
    {
        return $this->hasMany(Rank::class);
    }

    public function scopeVisibleToUser(Builder $query, User $user): Builder
    {
        if ($user->hasGlobalMdaAccess()) {
            return $query;
        }

        $accessibleMdaIds = $user->accessibleMdaIds();

        if ($accessibleMdaIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('department', fn (Builder $departmentQuery) => $departmentQuery->forMdas($accessibleMdaIds->all()));
    }
}
