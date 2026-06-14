<?php

namespace App\Domain\Organization\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'state',
        'lga',
        'ward',
        'town',
        'is_urban_center',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_urban_center' => 'boolean',
        ];
    }

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }
}
