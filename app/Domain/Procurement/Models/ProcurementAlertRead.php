<?php

namespace App\Domain\Procurement\Models;

use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;

class ProcurementAlertRead extends Model
{
    use HasMdaScope;

    public $timestamps = false;

    protected $fillable = ['mda_id', 'user_id', 'alert_key', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
