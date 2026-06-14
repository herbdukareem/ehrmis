<?php

namespace App\Policies;

use App\Domain\Movement\Models\MovementWorkbook;
use App\Models\User;
use App\Policies\Concerns\MdaScopedPolicy;

class MovementWorkbookPolicy
{
    use MdaScopedPolicy;

    public function viewAny(User $user): bool
    {
        return $user->can('view-movement-sheets');
    }

    public function view(User $user, MovementWorkbook $workbook): bool
    {
        return $user->can('view-movement-sheets') && $this->canAccessMda($user, $workbook->mda_id);
    }

    public function create(User $user): bool
    {
        return $user->can('create-movement-sheets');
    }

    public function review(User $user, MovementWorkbook $workbook): bool
    {
        return $user->can('create-movement-sheets') && $this->canAccessMda($user, $workbook->mda_id);
    }

    public function approve(User $user, MovementWorkbook $workbook): bool
    {
        return $user->can('approve-movement-sheets') && $this->canAccessMda($user, $workbook->mda_id);
    }
}
