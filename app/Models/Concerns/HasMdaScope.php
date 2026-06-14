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
}
