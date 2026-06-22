<?php

namespace App\Models;

use App\Domain\Organization\Models\Mda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_MDA = 'mda';

    protected $fillable = [
        'name',
        'guard_name',
        'scope',
        'mda_id',
    ];

    protected function casts(): array
    {
        return [
            'mda_id' => 'integer',
        ];
    }

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
    }

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_GLOBAL);
    }

    public function scopeForMda(Builder $query, int $mdaId): Builder
    {
        return $query
            ->where('scope', self::SCOPE_MDA)
            ->where('mda_id', $mdaId);
    }
}
