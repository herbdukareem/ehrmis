<?php

namespace App\Policies;

use App\Models\User;

class ReportAnalyticsPolicy
{
    public function view(User $user): bool
    {
        return $user->can('view-service-reports');
    }

    public function export(User $user): bool
    {
        return $user->can('export-service-reports');
    }
}
