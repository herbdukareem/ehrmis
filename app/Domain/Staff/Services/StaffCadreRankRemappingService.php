<?php

namespace App\Domain\Staff\Services;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class StaffCadreRankRemappingService
{
    public function __construct(protected StaffUpdateService $staffUpdates, protected AuditLogService $audit) {}

    /** Explicit, reviewed names only. Salary placement and existing catalogue definitions are never rewritten. */
    public function remap(Mda $mda, User $actor, array $mappings, array $source, bool $dryRun = true, ?callable $beforeWrite = null): array
    {
        abort_unless($actor->hasGlobalMdaAccess() && $actor->can('update-staff-appointment') && Auth::id() === $actor->id, 403);

        return DB::transaction(function () use ($mda, $actor, $mappings, $source, $dryRun, $beforeWrite): array {
            Mda::query()->visibleToUser($actor)->whereKey($mda->id)->lockForUpdate()->firstOrFail();
            $updates = $unchanged = $seen = [];
            foreach ($mappings as $mapping) {
                foreach (['staff_number', 'name', 'cadre', 'rank', 'salary_scale', 'level', 'step'] as $field) {
                    if (! isset($mapping[$field]) || trim((string) $mapping[$field]) === '') {
                        throw new InvalidArgumentException('Missing mapping field: '.$field);
                    }
                }
                if (isset($seen[$mapping['staff_number']])) {
                    throw new InvalidArgumentException('Duplicate staff mapping: '.$mapping['staff_number']);
                }
                $seen[$mapping['staff_number']] = true;
                $person = Staff::query()->forMda($mda->id)->where('staff_number', $mapping['staff_number'])->lockForUpdate()->sole();
                if ($this->key($person->full_name) !== $this->key($mapping['name'])) {
                    throw new InvalidArgumentException('Staff identity does not match: '.$mapping['staff_number']);
                }
                Gate::forUser($actor)->authorize('updateAppointment', $person);
                $employment = $person->currentEmployment()->with(['cadre', 'rank'])->lockForUpdate()->sole();
                $salary = $person->currentSalaryPlacement()->with('salaryScale')->lockForUpdate()->sole();
                Department::query()->forMda($mda->id)->whereKey($employment->department_id)->where('status', 'active')->firstOrFail();
                if ((int) $employment->mda_id !== (int) $mda->id || $employment->effective_from?->isFuture()) {
                    throw new InvalidArgumentException('Invalid or future-dated current appointment: '.$person->staff_number);
                }
                if ($salary->salaryScale?->code !== $mapping['salary_scale'] || $salary->level !== (int) $mapping['level'] || $salary->step !== (int) $mapping['step']) {
                    throw new InvalidArgumentException('Salary placement changed since review: '.$person->staff_number);
                }
                $cadre = $this->findCadre($employment->department_id, $salary->salary_scale_id, $mapping['cadre']);
                $rank = $cadre ? $this->findRank($cadre, $salary->level, $mapping['rank']) : null;
                $entry = [
                    'staff_id' => $person->id, 'staff_number' => $person->staff_number, 'name' => $person->full_name,
                    'department_id' => $employment->department_id, 'salary_scale_id' => $salary->salary_scale_id,
                    'salary_scale' => $salary->salaryScale->code, 'level' => $salary->level, 'step' => $salary->step,
                    'from_cadre' => $employment->cadre?->name, 'from_rank' => $employment->rank?->name,
                    'to_cadre' => $mapping['cadre'], 'to_rank' => $mapping['rank'],
                    'existing_cadre_id' => $cadre?->id, 'existing_rank_id' => $rank?->id,
                    'old_employment_id' => $employment->id, 'before' => $employment->getAttributes(),
                    'salary_before' => $salary->getAttributes(), 'evidence' => $mapping['evidence'] ?? [],
                ];
                if ($cadre && $rank && $employment->cadre_id === $cadre->id && $employment->rank_id === $rank->id) {
                    $unchanged[] = $entry;
                } else {
                    $updates[] = $entry;
                }
            }
            $report = ['mda_id' => $mda->id, 'source' => $source, 'dry_run' => $dryRun, 'updated' => count($updates), 'unchanged' => $unchanged, 'updates' => $updates, 'created_cadres' => [], 'created_ranks' => []];
            if ($dryRun) {
                return $report;
            }
            if ($beforeWrite) {
                $beforeWrite($report);
            }
            foreach ($report['updates'] as &$entry) {
                $person = Staff::query()->forMda($mda->id)->whereKey($entry['staff_id'])->firstOrFail();
                $employment = $person->currentEmployment()->lockForUpdate()->sole();
                $salary = $person->currentSalaryPlacement()->lockForUpdate()->sole();
                if ($employment->getAttributes() !== $entry['before'] || $salary->getAttributes() !== $entry['salary_before']) {
                    throw new InvalidArgumentException('Appointment or salary changed during remapping; all changes rolled back.');
                }
                $cadre = $this->findCadre($entry['department_id'], $entry['salary_scale_id'], $entry['to_cadre']);
                if (! $cadre) {
                    $cadre = Cadre::query()->create([
                        'department_id' => $entry['department_id'], 'salary_scale_id' => $entry['salary_scale_id'],
                        'name' => $entry['to_cadre'], 'status' => 'active',
                        'description' => 'Explicit staff cadre mapping from user-supplied workbook correction.',
                    ]);
                    $report['created_cadres'][] = $cadre->getAttributes();
                    $this->audit->logCreated($cadre, $source + ['mda_id' => $mda->id, 'source' => 'staff.cadre_rank_remapping']);
                }
                $rank = $this->findRank($cadre, $entry['level'], $entry['to_rank']);
                if (! $rank) {
                    $rank = Rank::query()->create([
                        'cadre_id' => $cadre->id, 'salary_scale_id' => $entry['salary_scale_id'],
                        'name' => $entry['to_rank'], 'level' => $entry['level'], 'status' => 'active',
                        'description' => 'User-specified rank label; grade retained from the staff salary placement. Existing rank definitions preserved.',
                    ]);
                    $report['created_ranks'][] = $rank->getAttributes();
                    $this->audit->logCreated($rank, $source + ['mda_id' => $mda->id, 'source' => 'staff.cadre_rank_remapping']);
                }
                $attributes = $employment->only($employment->getFillable());
                unset($attributes['staff_id'], $attributes['is_current'], $attributes['effective_to']);
                $attributes['cadre_id'] = $cadre->id;
                $attributes['rank_id'] = $rank->id;
                $attributes['effective_from'] = now()->toDateString();
                $corrected = $this->staffUpdates->createEmploymentRecord($person, $attributes);
                $entry['new_employment_id'] = $corrected->id;
                $entry['to_cadre_id'] = $cadre->id;
                $entry['to_rank_id'] = $rank->id;
                $entry['after'] = $corrected->getAttributes();
                $this->audit->log('staff.cadre_rank_remapped', $person, $entry['before'], $entry['after'], $source + [
                    'mda_id' => $mda->id, 'evidence' => $entry['evidence'],
                    'requested_cadre' => $entry['to_cadre'], 'requested_rank' => $entry['to_rank'],
                    'salary_placement_preserved' => $salary->id,
                ]);
            }
            unset($entry);

            return $report;
        });
    }

    protected function findCadre(int $departmentId, int $scaleId, string $name): ?Cadre
    {
        $matches = Cadre::withTrashed()->where('department_id', $departmentId)->where('salary_scale_id', $scaleId)
            ->get()->filter(fn ($c) => $this->key($c->name) === $this->key($name));
        if ($matches->count() > 1 || ($matches->isNotEmpty() && ($matches->first()->trashed() || $matches->first()->status !== 'active'))) {
            throw new InvalidArgumentException('Ambiguous or inactive cadre: '.$name);
        }

        return $matches->first();
    }

    protected function findRank(Cadre $cadre, int $level, string $name): ?Rank
    {
        $matches = Rank::withTrashed()->where('cadre_id', $cadre->id)->where('level', $level)
            ->get()->filter(fn ($r) => $this->key($r->name) === $this->key($name));
        if ($matches->count() > 1 || ($matches->isNotEmpty() && ($matches->first()->trashed() || $matches->first()->status !== 'active' || $matches->first()->salary_scale_id !== $cadre->salary_scale_id))) {
            throw new InvalidArgumentException('Ambiguous or incompatible rank: '.$name);
        }

        return $matches->first();
    }

    protected function key(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
    }
}
