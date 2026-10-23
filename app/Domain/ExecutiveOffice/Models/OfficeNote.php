<?php

namespace App\Domain\ExecutiveOffice\Models;

use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;

class OfficeNote extends Model
{
    use HasMdaScope;

    protected $table = 'executive_office_notes';

    protected $guarded = ['id'];
}
