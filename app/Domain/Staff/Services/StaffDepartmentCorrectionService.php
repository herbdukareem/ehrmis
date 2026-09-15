<?php

namespace App\Domain\Staff\Services;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class StaffDepartmentCorrectionService
{
    public function __construct(
        protected StaffWorkbookIdentityMatcher $matcher,
        protected StaffUpdateService $staffUpdates,
        protected AuditLogService $audit,
    ) {}

    public function correct(Mda $mda, User $actor, array $rows, array $source, bool $dryRun = false): array
    {
        abort_unless($actor->hasGlobalMdaAccess() && $actor->can('update-staff-appointment'), 403);

        return DB::transaction(function () use ($mda, $actor, $rows, $source, $dryRun): array {
            $mdaQuery = Mda::query()->visibleToUser($actor)->whereKey($mda->id);
            if (! $dryRun) {
                $mdaQuery->lockForUpdate();
            }
            $mdaQuery->firstOrFail();
            $staff = Staff::query()->forMda($mda->id)->with(['currentEmployment.department'])->orderBy('id')->get()->keyBy('id');
            $departments = Department::query()->forMda($mda->id)->where('status', 'active')->get();
            $departmentIndex = [];
            foreach ($departments as $department) {
                foreach ([$department->name, $department->code] as $name) {
                    $departmentIndex[$this->key($name)][$department->id] = $department->id;
                }
                if ($mda->code === 'HMB' && $department->code === 'HIM') {
                    $departmentIndex[$this->key('PRS/HIM')][$department->id] = $department->id;
                }
            }
            $identity = $this->matcher->match($mda, $staff, $rows);
            $updates = [];
            $issues = [];
            foreach ($identity['matched'] as $staffId => $group) {
                $person = $staff[$staffId];
                $targets = [];
                foreach ($group as $row) {
                    $ids = $departmentIndex[$this->key($row['department'] ?? '')] ?? [];
                    if (count($ids) !== 1) {
                        $issues[] = ['staff_id' => $staffId, 'source_row' => $row['row'], 'reason' => 'Department is missing or cannot be resolved uniquely.', 'department' => $row['department'] ?? null];

                        continue 2;
                    }
                    $targets[array_values($ids)[0]] = true;
                }
                if (count($targets) !== 1) {
                    $issues[] = ['staff_id' => $staffId, 'source_rows' => array_column($group, 'row'), 'reason' => 'Duplicate source entries disagree on department.'];

                    continue;
                }
                $targetId = array_key_first($targets);
                $employment = $person->currentEmployment;
                if (! $employment || (int) $employment->mda_id !== (int) $mda->id) {
                    $issues[] = ['staff_id' => $staffId, 'reason' => 'No valid current appointment exists in the selected MDA.'];

                    continue;
                }
                if ((int) $employment->department_id === (int) $targetId) {
                    continue;
                }
                Gate::forUser($actor)->authorize('updateAppointment', $person);
                if ($employment->effective_from?->isFuture()) {
                    throw new InvalidArgumentException('Staff '.$person->staff_number.' has a future appointment; review its effective date first.');
                }
                $updates[] = [
                    'staff_id' => $staffId, 'staff_number' => $person->staff_number, 'name' => $person->full_name,
                    'old_employment_id' => $employment->id, 'new_employment_id' => null,
                    'from_department_id' => $employment->department_id, 'from_department' => $employment->department?->name,
                    'to_department_id' => $targetId, 'to_department' => $departments->firstWhere('id', $targetId)->name,
                    'source_rows' => array_column($group, 'row'),
                    'before' => $employment->getAttributes(),
                ];
            }
            $affectedIds = array_column($updates, 'staff_id');
            $movement = MovementWorkbook::query()->where('mda_id', $mda->id)
                ->whereHas('lines', fn ($query) => $query->whereIn('staff_id', $affectedIds))
                ->withCount(['lines as affected_staff' => fn ($query) => $query->whereIn('staff_id', $affectedIds)])
                ->get(['id', 'name', 'year', 'status']);
            $budgets = BudgetWorkbook::query()->where('mda_id', $mda->id)->whereIn('movement_workbook_id', $movement->pluck('id'))
                ->get(['id', 'movement_workbook_id', 'year', 'status']);

            if (! $dryRun) {
                foreach ($updates as &$update) {
                    $person = $staff[$update['staff_id']];
                    // Append a corrected snapshot. Existing approved workbooks retain their saved appointment links.
                    $current = $person->currentEmployment()->lockForUpdate()->firstOrFail();
                    if ($current->getAttributes() !== $update['before']) {
                        throw new InvalidArgumentException('An appointment changed during this import. No corrections were saved; run the preview again.');
                    }
                    $attributes = $current->only($current->getFillable());
                    unset($attributes['staff_id'], $attributes['is_current'], $attributes['effective_to']);
                    $attributes['department_id'] = $update['to_department_id'];
                    $attributes['effective_from'] = now()->toDateString();
                    $corrected = $this->staffUpdates->createEmploymentRecord($person, $attributes);
                    $update['new_employment_id'] = $corrected->id;
                    $update['after'] = $corrected->getAttributes();
                    $this->audit->log('staff.department_corrected_from_workbook', $person, $update['before'], $update['after'], $source + [
                        'mda_id' => $mda->id, 'source_rows' => $update['source_rows'], 'reason' => 'Correct department to match the supplied staff list.',
                    ]);
                }
                unset($update);
            }

            return [
                'dry_run' => $dryRun, 'mda' => $mda->code, 'source' => $source, 'source_rows' => count($rows),
                'matched_staff' => count($identity['matched']), 'updated' => count($updates),
                'unmatched_rows' => $identity['unmatched'], 'issues' => $issues,
                'staff_outside_source' => $staff->except(array_keys($identity['matched']))->keys()->all(),
                'affected_movement_workbooks' => $movement->toArray(), 'affected_budget_workbooks' => $budgets->toArray(),
                'updates' => $updates,
            ];
        });
    }

    protected function key(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
    }
}
