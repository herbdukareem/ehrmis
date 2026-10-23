<?php

namespace App\Domain\ExecutiveOffice\Models;

use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OfficeDelegation extends Model
{
    use HasMdaScope;

    protected $table = 'executive_office_delegations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'executive_only_access' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }
}
