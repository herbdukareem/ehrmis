<?php

namespace App\Domain\Posting\Models;

use App\Domain\Staff\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPostingRequestItem extends Model
{
    protected $fillable = [
        'posting_request_id',
        'staff_id',
        'staff_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'staff_snapshot' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StaffPostingRequest::class, 'posting_request_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
