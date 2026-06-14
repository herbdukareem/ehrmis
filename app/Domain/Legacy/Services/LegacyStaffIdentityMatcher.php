<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Staff\Models\Staff;

class LegacyStaffIdentityMatcher
{
    public function match(array $normalizedRow): ?Staff
    {
        $mdaId = $normalizedRow['mda_id'] ?? null;

        if (! $mdaId) {
            return null;
        }

        if (! empty($normalizedRow['legacy_cno_psn'])) {
            $staff = Staff::withoutGlobalScopes()
                ->where('mda_id', $mdaId)
                ->where('legacy_cno_psn', $normalizedRow['legacy_cno_psn'])
                ->first();

            if ($staff) {
                return $staff;
            }
        }

        if (! empty($normalizedRow['legacy_cno'])) {
            $staff = Staff::withoutGlobalScopes()
                ->where('mda_id', $mdaId)
                ->where('legacy_cno', $normalizedRow['legacy_cno'])
                ->first();

            if ($staff) {
                return $staff;
            }
        }

        if (! empty($normalizedRow['legacy_psn'])) {
            $staff = Staff::withoutGlobalScopes()
                ->where('mda_id', $mdaId)
                ->where('legacy_psn', $normalizedRow['legacy_psn'])
                ->first();

            if ($staff) {
                return $staff;
            }
        }

        if (! empty($normalizedRow['full_name']) && ! empty($normalizedRow['date_of_birth'])) {
            return Staff::withoutGlobalScopes()
                ->where('mda_id', $mdaId)
                ->whereRaw('LOWER(full_name) = ?', [strtolower($normalizedRow['full_name'])])
                ->whereDate('date_of_birth', $normalizedRow['date_of_birth'])
                ->first();
        }

        return null;
    }
}
