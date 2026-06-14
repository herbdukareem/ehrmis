<?php

namespace App\Domain\Organization\Models;

use App\Models\Concerns\HasMdaScope;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, HasMdaScope, SoftDeletes;

    protected $fillable = [
        'mda_id',
        'code',
        'name',
        'description',
        'status',
    ];

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
    }

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }
}
