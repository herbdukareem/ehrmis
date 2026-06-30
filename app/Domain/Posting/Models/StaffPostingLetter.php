<?php

namespace App\Domain\Posting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPostingLetter extends Model
{
    protected $fillable = [
        'posting_request_id',
        'letter_number',
        'status',
        'pdf_path',
        'generated_by',
        'generated_at',
        'printed_by',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StaffPostingRequest::class, 'posting_request_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
