<?php

namespace App\Domain\Approval\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalStep extends Model
{
    protected const LEGACY_WORKFLOW_PERMISSION_MAP = [
        'legacy_staff_import_publication' => ['approve-staff-imports'],
        'movement_workbook_approval' => ['approve-movement-sheets'],
        'budget_workbook_approval' => ['approve-budgets'],
        'promotion_sitting_print_authorization' => ['approve-promotion-printing'],
    ];

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

        $requiredPermissions = collect(data_get($this->metadata ?? [], 'required_permissions', []))
            ->filter(fn ($permission): bool => is_string($permission) && $permission !== '')
            ->values();

        $requiredPermission = data_get($this->metadata ?? [], 'required_permission');

        if (is_string($requiredPermission) && $requiredPermission !== '') {
            $requiredPermissions->prepend($requiredPermission);
        }

        if ($requiredPermissions->isEmpty()) {
            $requiredPermissions = collect($this->legacyRequiredPermissions());
        }

        if ($requiredPermissions->isNotEmpty() && $requiredPermissions->contains(fn (string $permission): bool => $user->can($permission))) {
            return true;
        }

        if ($this->reviewer_role !== null && $user->hasRole($this->reviewer_role)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function legacyRequiredPermissions(): array
    {
        $workflowType = $this->relationLoaded('workflow')
            ? $this->workflow?->workflow_type
            : $this->workflow()->value('workflow_type');

        if (! is_string($workflowType) || $workflowType === '') {
            return [];
        }

        return self::LEGACY_WORKFLOW_PERMISSION_MAP[$workflowType] ?? [];
    }
}
