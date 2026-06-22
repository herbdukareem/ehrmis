<?php

namespace App\Domain\Organization\Models;

use App\Models\User;
use Database\Factories\MdaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mda extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'status',
    ];

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function setting(): HasOne
    {
        return $this->hasOne(MdaSetting::class);
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

        return $query->whereKey($accessibleMdaIds->all());
    }

    protected static function newFactory(): MdaFactory
    {
        return MdaFactory::new();
    }
}
