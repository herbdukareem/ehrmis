<?php

namespace App\Domain\Legacy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyStaffImportPublication extends Model
{
    protected $fillable = [
        'batch_id',
        'published_by',
        'published_at',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LegacyStaffImportBatch::class, 'batch_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
