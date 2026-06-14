<?php

namespace App\Domain\Legacy\Models;

use App\Domain\Approval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class LegacyStaffImportBatch extends Model
{
    protected $fillable = [
        'source_database',
        'source_table',
        'status',
        'started_at',
        'completed_at',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(LegacyStaffImportRow::class, 'batch_id');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(LegacyStaffImportError::class, 'batch_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(LegacyStaffImportPublication::class, 'batch_id');
    }

    public function approvalWorkflow(): MorphOne
    {
        return $this->morphOne(ApprovalWorkflow::class, 'subject');
    }

    public function scopedRowsCount(?int $mdaId = null): int
    {
        return $this->rows()
            ->when($mdaId !== null, fn ($query) => $query->where('mda_id', $mdaId))
            ->count();
    }
}
