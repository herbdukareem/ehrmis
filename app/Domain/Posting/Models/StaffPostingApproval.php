<?php

namespace App\Domain\Posting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPostingApproval extends Model
{
    protected $fillable = [
        'posting_request_id',
        'stage',
        'decision',
        'comment',
        'acted_by',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StaffPostingRequest::class, 'posting_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
