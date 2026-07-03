<?php

namespace App\Policies;

use App\Domain\ServiceReporting\Models\ReportSubmission;
use App\Models\User;

class ReportSubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-service-reports');
    }

    public function view(User $user, ReportSubmission $submission): bool
    {
        return $user->can('view-service-reports') && $user->canAccessMda((int) $submission->mda_id);
    }

    public function create(User $user): bool
    {
        return $user->can('create-service-reports');
    }

    public function update(User $user, ReportSubmission $submission): bool
    {
        return $user->can('create-service-reports')
            && $user->canAccessMda((int) $submission->mda_id)
            && $submission->canEditValues();
    }

    public function submit(User $user, ReportSubmission $submission): bool
    {
        return $user->can('submit-service-reports') && $user->canAccessMda((int) $submission->mda_id);
    }

    public function review(User $user, ReportSubmission $submission): bool
    {
        return $user->can('review-service-reports') && $user->canAccessMda((int) $submission->mda_id);
    }

    public function approve(User $user, ReportSubmission $submission): bool
    {
        return $user->can('approve-service-reports') && $user->canAccessMda((int) $submission->mda_id);
    }

    public function lock(User $user, ReportSubmission $submission): bool
    {
        return $user->can('lock-service-reports') && $user->canAccessMda((int) $submission->mda_id);
    }
}
