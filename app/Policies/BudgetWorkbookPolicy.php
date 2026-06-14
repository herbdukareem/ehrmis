<?php

namespace App\Policies;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Models\User;
use App\Policies\Concerns\MdaScopedPolicy;

class BudgetWorkbookPolicy
{
    use MdaScopedPolicy;

    public function viewAny(User $user): bool
    {
        return $user->can('view-budgets');
    }

    public function view(User $user, BudgetWorkbook $workbook): bool
    {
        return $user->can('view-budgets') && $this->canAccessMda($user, $workbook->mda_id);
    }

    public function create(User $user): bool
    {
        return $user->can('create-budgets');
    }

    public function approve(User $user, BudgetWorkbook $workbook): bool
    {
        return $user->can('approve-budgets') && $this->canAccessMda($user, $workbook->mda_id);
    }
}
