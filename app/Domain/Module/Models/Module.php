<?php

namespace App\Domain\Module\Models;

use App\Domain\Organization\Models\Mda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'icon',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(ModulePermission::class)->orderBy('sort_order')->orderBy('permission_name');
    }

    public function mdaModules(): HasMany
    {
        return $this->hasMany(MdaModule::class);
    }

    public function mdas(): BelongsToMany
    {
        return $this->belongsToMany(Mda::class, 'mda_modules')
            ->withPivot(['enabled', 'enabled_by', 'enabled_at', 'disabled_at'])
            ->withTimestamps();
    }

    public function roleTemplates(): HasMany
    {
        return $this->hasMany(ModuleRoleTemplate::class)->orderBy('sort_order')->orderBy('name');
    }
}
