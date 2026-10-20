<?php

namespace App\Domain\Budget\Services;

use App\Domain\Budget\Models\BudgetCycle;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BudgetCycleService
{
    public function __construct(private AuditLogService $audit) {}

    public function create(array $data, User $actor): BudgetCycle
    {
        return DB::transaction(function () use ($data, $actor): BudgetCycle {
            $cycle = BudgetCycle::query()->create([
                'mda_id' => $data['mda_id'], 'year' => $data['year'],
                'name' => $data['name'] ?? null, 'status' => 'draft', 'created_by' => $actor->id,
            ]);
            $this->audit->logCreated($cycle, ['source' => 'budget_cycle.created']);

            return $cycle;
        });
    }

    public function submit(BudgetCycle $cycle, User $actor): BudgetCycle
    {
        return $this->transition($cycle, $actor, ['draft'], 'submitted', [
            'submitted_by' => $actor->id, 'submitted_at' => now(),
        ]);
    }

    public function approve(BudgetCycle $cycle, User $actor): BudgetCycle
    {
        return $this->transition($cycle, $actor, ['submitted'], 'approved', [
            'approved_by' => $actor->id, 'approved_at' => now(), 'opened_at' => now(),
        ]);
    }

    private function transition(BudgetCycle $cycle, User $actor, array $from, string $to, array $attributes): BudgetCycle
    {
        return DB::transaction(function () use ($cycle, $actor, $from, $to, $attributes): BudgetCycle {
            $cycle = BudgetCycle::query()->lockForUpdate()->findOrFail($cycle->id);
            if (! in_array($cycle->status, $from, true) || $cycle->closed_at || $cycle->locked_at) {
                throw ValidationException::withMessages(['status' => 'Invalid budget cycle transition.']);
            }
            $before = $cycle->toArray();
            $cycle->forceFill(['status' => $to, ...$attributes])->save();
            $this->audit->logUpdated($cycle, $before, ['source' => 'budget_cycle.'.$to, 'actor_id' => $actor->id]);

            return $cycle->fresh();
        });
    }
}
