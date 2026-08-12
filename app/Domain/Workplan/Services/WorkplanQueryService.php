<?php

namespace App\Domain\Workplan\Services;

use App\Domain\Workplan\Models\Workplan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class WorkplanQueryService
{
    public function list(User $user, array $filters = []): Builder
    {
        $query = Workplan::query()->with('mda')->latest('year')->latest('revision_no');
        if (isset($filters['mda_id'])) $query->where('mda_id', $filters['mda_id']);
        if (isset($filters['year'])) $query->where('year', $filters['year']);
        if (isset($filters['status'])) $query->where('status', $filters['status']);
        return $query;
    }
    public function detail(Workplan $workplan): Workplan
    {
        return $workplan->load(['mda','preparedBy','submittedBy','approvedBy','activatedBy','closedBy','supersedes','supersededBy','objectives.department','objectives.activities.department','objectives.activities.responsibleStaff.currentEmployment.department','objectives.activities.supportAssignments.staff','objectives.activities.indicators.targets','approvalWorkflow.steps.actedBy']);
    }
}
