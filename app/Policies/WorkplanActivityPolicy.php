<?php

namespace App\Policies;

use App\Domain\Workplan\Models\WorkplanActivity;
use App\Models\User;

class WorkplanActivityPolicy
{
    public function update(User $user, WorkplanActivity $activity): bool { return $user->can('update-workplans') && $user->canAccessMda($activity->mda_id) && ($activity->department_id === null || $user->canAccessDepartment($activity->department_id)) && $activity->objective->workplan->isEditable(); }
    public function delete(User $user, WorkplanActivity $activity): bool { return $this->update($user, $activity); }
}
