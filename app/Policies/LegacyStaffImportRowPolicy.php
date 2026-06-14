<?php

namespace App\Policies;

use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Models\User;
use App\Policies\Concerns\MdaScopedPolicy;

class LegacyStaffImportRowPolicy
{
    use MdaScopedPolicy;

    public function view(User $user, LegacyStaffImportRow $row): bool
    {
        return $user->can('view-staff-imports') && $this->canAccessMda($user, $row->mda_id);
    }

    public function resolveMapping(User $user, LegacyStaffImportRow $row): bool
    {
        return $user->can('resolve-staff-import-issues') && $this->canAccessMda($user, $row->mda_id);
    }

    public function ignoreWarning(User $user, LegacyStaffImportRow $row): bool
    {
        return $user->can('resolve-staff-import-issues') && $this->canAccessMda($user, $row->mda_id);
    }

    public function publish(User $user, LegacyStaffImportRow $row): bool
    {
        if (! $this->canAccessMda($user, $row->mda_id)) {
            return false;
        }

        if (($row->batch?->approvalWorkflow?->status ?? null) !== 'approved') {
            return false;
        }

        return $user->hasGlobalMdaAccess()
            ? $user->can('publish-staff-imports')
            : $user->can('publish-own-mda-staff-imports');
    }
}
