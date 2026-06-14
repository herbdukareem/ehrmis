<?php

namespace App\Domain\Approval\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalStep extends Model
{
    protected $fillable = [
        'workflow_id',
        'step_no',
        'reviewer_user_id',
        'reviewer_role',
        'status',
        'comment',
        'acted_at',
        'acted_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function reviewerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function isActionableBy(User $user): bool
    {
        if ($this->reviewer_user_id !== null) {
            return (int) $this->reviewer_user_id === (int) $user->id;
        }

        if ($this->reviewer_role !== null) {
            return $user->hasRole($this->reviewer_role);
        }

        return false;
    }
}
