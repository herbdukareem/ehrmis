<?php

namespace App\Policies;

use App\Domain\Workplan\Models\Workplan;
use App\Models\User;
use App\Policies\Concerns\MdaScopedPolicy;

class WorkplanPolicy
{
    use MdaScopedPolicy;
    public function viewAny(User $user): bool { return $user->can('view-workplans'); }
    public function view(User $user, Workplan $workplan): bool { return $user->can('view-workplans') && $this->canAccessMda($user, $workplan->mda_id); }
    public function create(User $user): bool { return $user->can('create-workplans'); }
    public function update(User $user, Workplan $workplan): bool { return $user->can('update-workplans') && $this->canAccessMda($user, $workplan->mda_id) && $workplan->isEditable(); }
}
