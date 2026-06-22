<?php

namespace App\Domain\Staff\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rank extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'legacy_id',
        'cadre_id',
        'salary_scale_id',
        'name',
        'level',
        'description',
        'status',
    ];

    public function cadre(): BelongsTo
    {
        return $this->belongsTo(Cadre::class);
    }

    public function salaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class);
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

        return $query->whereHas('cadre.department', fn (Builder $departmentQuery) => $departmentQuery->forMdas($accessibleMdaIds->all()));
    }
}
