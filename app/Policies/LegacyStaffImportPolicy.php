<?php

namespace App\Policies;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Models\User;

class LegacyStaffImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-staff-imports');
    }

    public function view(User $user, LegacyStaffImportBatch $batch): bool
    {
        return $user->can('view-staff-imports') && $this->canAccessBatch($user, $batch);
    }

    public function publish(User $user, LegacyStaffImportBatch $batch): bool
    {
        if (! $this->canAccessBatch($user, $batch)) {
            return false;
        }

        if (($batch->approvalWorkflow?->status ?? null) !== 'approved') {
            return false;
        }

        return $user->hasGlobalMdaAccess()
            ? $user->can('publish-staff-imports')
            : $user->can('publish-own-mda-staff-imports');
    }

    public function submitApproval(User $user, LegacyStaffImportBatch $batch): bool
    {
        return $user->can('review-staff-imports') && $this->canAccessBatch($user, $batch);
    }

    public function approveApproval(User $user, LegacyStaffImportBatch $batch): bool
    {
        return $user->can('approve-staff-imports') && $this->canAccessBatch($user, $batch);
    }

    public function rejectApproval(User $user, LegacyStaffImportBatch $batch): bool
    {
        return $user->can('approve-staff-imports') && $this->canAccessBatch($user, $batch);
    }

    protected function canAccessBatch(User $user, LegacyStaffImportBatch $batch): bool
    {
        if ($user->hasGlobalMdaAccess()) {
            return true;
        }

        if (! $user->mda_id) {
            return false;
        }

        return $batch->rows()->where('mda_id', $user->mda_id)->exists();
    }
}
