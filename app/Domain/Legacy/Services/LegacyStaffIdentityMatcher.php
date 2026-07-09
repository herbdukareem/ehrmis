<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Legacy\Support\LegacyIdentifier;
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

        $legacyCnoPsn = LegacyIdentifier::normalize($normalizedRow['legacy_cno_psn'] ?? null);
        $legacyCno = LegacyIdentifier::normalize($normalizedRow['legacy_cno'] ?? null);
        if ($legacyCnoPsn !== null) {
            $staff = (clone $query)
                ->where('legacy_cno_psn', $legacyCnoPsn)
                ->first();

            if ($staff) {
                return $staff;
            }
        }

        if ($legacyCno !== null) {
            $staff = (clone $query)
                ->where('legacy_cno', $legacyCno)
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
