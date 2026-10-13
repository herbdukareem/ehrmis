<?php

namespace App\Domain\Budget\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetAccount extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'parent_id',
        'code',
        'name',
        'account_type',
        'description',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('code'); }
    public function allocations(): HasMany { return $this->hasMany(BudgetAllocation::class); }
    public function transactions(): HasMany { return $this->hasMany(BudgetTransaction::class); }
}
