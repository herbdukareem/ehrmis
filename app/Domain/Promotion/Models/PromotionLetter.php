<?php

namespace App\Domain\Promotion\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionLetter extends Model
{
    protected $fillable = [
        'application_id',
        'letter_number',
        'effective_date',
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
            'effective_date' => 'date',
            'generated_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PromotionApplication::class, 'application_id');
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
