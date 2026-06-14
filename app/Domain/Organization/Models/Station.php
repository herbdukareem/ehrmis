<?php

namespace App\Domain\Organization\Models;

use App\Models\Concerns\HasMdaScope;
use Database\Factories\StationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Station extends Model
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

    protected static function newFactory(): StationFactory
    {
        return StationFactory::new();
    }
}
