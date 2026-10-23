<?php

namespace App\Domain\ExecutiveOffice\Models;

use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;

class OfficeLink extends Model
{
    use HasMdaScope;

    protected $table = 'executive_office_links';

    protected $guarded = ['id'];
}
