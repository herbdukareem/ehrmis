<?php

namespace App\Domain\Staff\Services;

use Carbon\Carbon;

class RetirementPolicyService
{
    public function calculateRetirementByAge(?Carbon $dateOfBirth): ?Carbon
    {
        return $dateOfBirth?->copy()->addYears(60);
    }

    public function calculateRetirementByService(?Carbon $dateOfFirstAppointment): ?Carbon
    {
        return $dateOfFirstAppointment?->copy()->addYears(35);
    }

    public function calculateExpectedRetirementDate(?Carbon $dateOfBirth, ?Carbon $dateOfFirstAppointment): ?Carbon
    {
        $retirementByAge = $this->calculateRetirementByAge($dateOfBirth);
        $retirementByService = $this->calculateRetirementByService($dateOfFirstAppointment);

        if ($retirementByAge && $retirementByService) {
            return $retirementByAge->lte($retirementByService) ? $retirementByAge : $retirementByService;
        }

        return $retirementByAge ?? $retirementByService;
    }
}
