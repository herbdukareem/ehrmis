<?php

namespace App\Domain\Staff\Services;

use Carbon\CarbonInterface;

class StaffRetirementService
{
    public function isRetired(
        ?string $staffStatus,
        ?string $employmentStatus,
        mixed $expectedRetirementDate,
        ?CarbonInterface $asOf = null,
    ): bool {
        if ($staffStatus === 'retired' || $employmentStatus === 'retired') {
            return true;
        }

        if ($expectedRetirementDate === null) {
            return false;
        }

        $retirementDate = $expectedRetirementDate instanceof CarbonInterface
            ? $expectedRetirementDate->copy()
            : now()->parse((string) $expectedRetirementDate);

        return $retirementDate->lte(($asOf ?? now())->copy()->endOfDay());
    }

    public function state(
        ?string $staffStatus,
        ?string $employmentStatus,
        mixed $expectedRetirementDate,
        ?CarbonInterface $asOf = null,
    ): string {
        return $this->isRetired($staffStatus, $employmentStatus, $expectedRetirementDate, $asOf)
            ? 'retired'
            : 'active';
    }
}
