<?php

namespace App\Models\Concerns;

use App\Scopes\MdaScope;
use Illuminate\Database\Eloquent\Builder;

trait HasMdaScope
{
    protected static function bootHasMdaScope(): void
    {
        static::addGlobalScope(new MdaScope());
    }

    public function getMdaScopeColumn(): string
    {
        return 'mda_id';
    }

    public function scopeForMda(Builder $query, int $mdaId): Builder
    {
        return $query
            ->withoutGlobalScope(MdaScope::class)
            ->where($this->qualifyColumn($this->getMdaScopeColumn()), $mdaId);
    }

    public function scopeForMdas(Builder $query, iterable $mdaIds): Builder
    {
        $mdaIds = collect($mdaIds)
            ->filter(fn (mixed $mdaId): bool => $mdaId !== null && $mdaId !== '')
            ->map(fn (mixed $mdaId): int => (int) $mdaId)
            ->unique()
            ->values();

        if ($mdaIds->isEmpty()) {
            return $query
                ->withoutGlobalScope(MdaScope::class)
                ->whereRaw('1 = 0');
        }

        return $query
            ->withoutGlobalScope(MdaScope::class)
            ->whereIn($this->qualifyColumn($this->getMdaScopeColumn()), $mdaIds->all());
    }
}
