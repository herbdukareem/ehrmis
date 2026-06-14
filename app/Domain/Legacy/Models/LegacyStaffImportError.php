<?php

namespace App\Domain\Legacy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyStaffImportError extends Model
{
    protected $fillable = [
        'batch_id',
        'row_id',
        'field',
        'error_code',
        'message',
        'severity',
        'resolved_at',
        'resolved_by',
        'ignored_at',
        'ignored_by',
        'resolution_notes',
        'resolution_context',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'ignored_at' => 'datetime',
            'resolution_context' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LegacyStaffImportBatch::class, 'batch_id');
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(LegacyStaffImportRow::class, 'row_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function ignoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by');
    }
}
