<?php

namespace App\Policies;

use App\Domain\Workplan\Models\WorkplanIndicator;
use App\Models\User;

class WorkplanIndicatorPolicy
{
    public function update(User $user, WorkplanIndicator $indicator): bool { $activity = $indicator->activity; return $user->can('update-workplans') && $user->canAccessMda($indicator->mda_id) && ($activity->department_id === null || $user->canAccessDepartment($activity->department_id)) && $activity->objective->workplan->isEditable(); }
    public function delete(User $user, WorkplanIndicator $indicator): bool { return $this->update($user, $indicator); }
}
