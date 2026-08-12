<?php

namespace App\Policies;

use App\Domain\Workplan\Models\WorkplanObjective;
use App\Models\User;

class WorkplanObjectivePolicy
{
    public function update(User $user, WorkplanObjective $objective): bool { return $user->can('update-workplans') && $user->canAccessMda($objective->mda_id) && ($objective->department_id === null || $user->canAccessDepartment($objective->department_id)) && $objective->workplan->isEditable(); }
    public function delete(User $user, WorkplanObjective $objective): bool { return $this->update($user, $objective); }
}
