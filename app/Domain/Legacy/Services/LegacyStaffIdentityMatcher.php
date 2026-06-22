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

        $query = Staff::query()->forMda((int) $mdaId);

        if (! empty($normalizedRow['legacy_cno_psn'])) {
            $staff = (clone $query)
                ->where('legacy_cno_psn', $normalizedRow['legacy_cno_psn'])
                ->first();

            if ($staff) {
                return $staff;
            }
        }

        if (! empty($normalizedRow['legacy_cno'])) {
            $staff = (clone $query)
                ->where('legacy_cno', $normalizedRow['legacy_cno'])
                ->first();

            if ($staff) {
                return $staff;
            }
        }

        if (! empty($normalizedRow['legacy_psn'])) {
            $staff = (clone $query)
                ->where('legacy_psn', $normalizedRow['legacy_psn'])
                ->first();

            if ($staff) {
                return $staff;
            }
        }

        if (! empty($normalizedRow['full_name']) && ! empty($normalizedRow['date_of_birth'])) {
            return (clone $query)
                ->whereRaw('LOWER(full_name) = ?', [strtolower($normalizedRow['full_name'])])
                ->whereDate('date_of_birth', $normalizedRow['date_of_birth'])
                ->first();
        }

        return null;
    }
}
