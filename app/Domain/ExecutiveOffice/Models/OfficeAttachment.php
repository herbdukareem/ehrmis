<?php

namespace App\Domain\ExecutiveOffice\Models;

use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;

class OfficeAttachment extends Model
{
    use HasMdaScope;

    protected $table = 'executive_office_attachments';

    protected $guarded = ['id'];

    protected $hidden = ['path'];
}
