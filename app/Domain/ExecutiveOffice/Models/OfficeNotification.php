<?php

namespace App\Domain\ExecutiveOffice\Models;

use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;

class OfficeNotification extends Model
{
    use HasMdaScope;

    protected $table = 'executive_office_notifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
