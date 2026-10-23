<?php

namespace App\Domain\ExecutiveOffice\Models;

use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;

class OfficeEvent extends Model
{
    use HasMdaScope;

    protected $table = 'executive_office_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
