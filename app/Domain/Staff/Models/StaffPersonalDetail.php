<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPersonalDetail extends Model
{
    protected $fillable = [
        'staff_id',
        'lga',
        'state_of_origin',
        'phone',
        'email',
        'address',
        'marital_status',
        'file_no',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
