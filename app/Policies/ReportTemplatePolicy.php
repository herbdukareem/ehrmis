<?php

namespace App\Policies;

use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Models\User;

class ReportTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-service-reports');
    }

    public function view(User $user, ReportTemplate $template): bool
    {
        return $user->can('view-service-reports')
            && $template->assignments()
                ->whereIn('mda_id', $user->hasGlobalMdaAccess() ? $template->assignments()->pluck('mda_id') : $user->accessibleMdaIds())
                ->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('manage-report-templates');
    }

    public function update(User $user, ReportTemplate $template): bool
    {
        return $user->can('manage-report-templates');
    }

    public function assign(User $user, ReportTemplate $template): bool
    {
        return $user->can('assign-report-templates');
    }
}
