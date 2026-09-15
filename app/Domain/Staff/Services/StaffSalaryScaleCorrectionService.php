<?php

namespace App\Domain\Staff\Services;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class StaffSalaryScaleCorrectionService
{
    public function __construct(
        protected StaffWorkbookIdentityMatcher $matcher,
        protected SalaryCalculationService $salary,
        protected StaffSalaryPlacementService $placements,
        protected AuditLogService $audit,
    ) {}

    public function correct(Mda $mda, User $actor, array $rows, array $source, bool $dryRun = true, ?callable $beforeWrite = null): array
    {
        abort_unless($actor->hasGlobalMdaAccess() && $actor->can('update-staff-appointment'), 403);
        abort_unless(Auth::id() === $actor->id, 403);

        return DB::transaction(function () use ($mda, $actor, $rows, $source, $dryRun, $beforeWrite): array {
            Mda::query()->visibleToUser($actor)->whereKey($mda->id)->lockForUpdate()->firstOrFail();
            $staff = Staff::query()->forMda($mda->id)
                ->with(['currentEmployment.cadre', 'currentEmployment.rank', 'currentSalaryPlacement.salaryScale'])
                ->withCount('currentSalaryPlacement')->orderBy('id')->get()->keyBy('id');
            $identity = $this->matcher->match($mda, $staff, $rows);
            $scales = SalaryScale::query()->where('status', 'active')->get()->keyBy('code');
            $updates = $issues = $verified = [];

            foreach ($identity['matched'] as $id => $group) {
                $person = $staff[$id];
                $current = $person->currentSalaryPlacement;
                $entry = [
                    'staff_id' => $id, 'staff_number' => $person->staff_number, 'name' => $person->full_name,
                    'source_rows' => array_column($group, 'row'),
                    'current' => $current ? $current->salaryScale?->code.' '.$current->level.'/'.$current->step : null,
                    'source_values' => array_column($group, 'level_step'),
                ];
                $targets = [];
                foreach ($group as $row) {
                    $target = $this->parsePlacement((string) ($row['level_step'] ?? ''));
                    if (! $target) {
                        $issues[] = $entry + ['reason' => 'Source placement is missing or invalid.'];

                        continue 2;
                    }
                    $targets[implode('|', $target)] = $target;
                }
                if (count($targets) !== 1) {
                    $issues[] = $entry + ['reason' => 'Duplicate source rows disagree on grade or step; no authoritative choice can be made.'];

                    continue;
                }
                $target = array_values($targets)[0];
                $scale = $scales[$target['scale']] ?? null;
                if (! $scale || ! $current || $person->current_salary_placement_count !== 1) {
                    $issues[] = $entry + ['reason' => 'Requires one current placement and a known active source salary scale.'];

                    continue;
                }
                if ($current->salary_scale_id === $scale->id && $current->level === $target['level'] && $current->step === $target['step']) {
                    $verified[] = $entry;

                    continue;
                }
                $rate = $this->salary->getRate($scale->code, $target['level'], $target['step'], $mda->id);
                if (! $rate || $rate->status !== 'active' || $rate->basic_salary === null) {
                    $issues[] = $entry + ['reason' => 'No active approved salary rate exists for the source grade/step; do not invent a rate or clamp the step.'];

                    continue;
                }
                if ($current->effective_from?->isFuture()) {
                    $issues[] = $entry + ['reason' => 'Current placement is future-dated; review its effective date first.'];

                    continue;
                }
                Gate::forUser($actor)->authorize('updateAppointment', $person);
                $evidence = LegacyStaffImportRow::query()->where('mda_id', $mda->id)
                    ->where('published_staff_id', $id)->orderBy('id')->get()->map(fn ($r) => [
                        'import_row_id' => $r->id, 'batch_id' => $r->batch_id,
                        'upload_row' => $r->raw_payload['upload_row'] ?? null,
                        'uploaded' => collect($r->raw_payload['source_row'] ?? [])->only(['salary_scale', 'level', 'step', 'department', 'cadre', 'rank'])->all(),
                        'normalized' => collect($r->normalized_payload ?? [])->only(['salary_scale_code', 'level', 'step'])->all(),
                    ])->all();
                $uploadMismatch = collect($evidence)->contains(fn ($e) => isset($e['uploaded']['salary_scale']) && strtoupper($e['uploaded']['salary_scale']) !== $scale->code);
                $updates[] = $entry + [
                    'target' => $target, 'to_salary_scale_id' => $scale->id,
                    'before' => $current->getAttributes(),
                    'old_placement_id' => $current->id,
                    'source_cadre' => $group[0]['source_cadre'] ?? null,
                    'source_rank' => $group[0]['source_rank'] ?? null,
                    'current_cadre' => $person->currentEmployment?->cadre?->name,
                    'current_rank' => $person->currentEmployment?->rank?->name,
                    'appointment_scale_conflict' => ($person->currentEmployment?->cadre && $person->currentEmployment->cadre->salary_scale_id !== $scale->id)
                        || ($person->currentEmployment?->rank && $person->currentEmployment->rank->salary_scale_id !== $scale->id),
                    'import_evidence' => $evidence,
                    'reason' => $uploadMismatch
                        ? 'The saved upload already differs from the supplied workbook; publication retained that uploaded scale. The precise preprocessing rule is not recorded.'
                        : 'Current placement differs from the supplied workbook; inspect the saved import evidence and salary history.',
                ];
            }

            $affectedIds = array_column($updates, 'staff_id');
            $movement = MovementWorkbook::query()->where('mda_id', $mda->id)
                ->whereHas('lines', fn ($q) => $q->whereIn('staff_id', $affectedIds))
                ->withCount(['lines as affected_staff' => fn ($q) => $q->whereIn('staff_id', $affectedIds)])
                ->get(['id', 'name', 'year', 'budget_year', 'status']);
            $budgets = BudgetWorkbook::query()->where('mda_id', $mda->id)->whereIn('movement_workbook_id', $movement->pluck('id'))
                ->get(['id', 'movement_workbook_id', 'year', 'status']);
            $report = [
                'dry_run' => $dryRun, 'mda_id' => $mda->id, 'mda' => $mda->code, 'source' => $source,
                'source_rows' => count($rows), 'total_staff' => $staff->count(), 'matched_staff' => count($identity['matched']),
                'updated' => count($updates), 'verified_count' => count($verified), 'verified' => $verified,
                'issues' => $issues, 'unmatched_rows' => $identity['unmatched'],
                'staff_outside_source' => $staff->except(array_keys($identity['matched']))->keys()->all(),
                'affected_movement_workbooks' => $movement->toArray(), 'affected_budget_workbooks' => $budgets->toArray(),
                'updates' => $updates,
            ];

            if (! $dryRun) {
                // Persist the complete before state before changing any salary record.
                if ($beforeWrite) {
                    $beforeWrite($report);
                }
                foreach ($report['updates'] as &$update) {
                    $person = $staff[$update['staff_id']];
                    $current = $person->currentSalaryPlacement()->lockForUpdate()->get();
                    if ($current->count() !== 1 || $current->first()->getAttributes() !== $update['before']) {
                        throw new InvalidArgumentException('A salary placement changed during correction. All changes were rolled back; run the audit again.');
                    }
                    $placement = $this->placements->createPlacement($person, [
                        'salary_scale' => $scales[$update['target']['scale']],
                        'level' => $update['target']['level'], 'step' => $update['target']['step'],
                        'source' => 'workbook_salary_correction', 'effective_from' => now()->toDateString(),
                    ]);
                    $update['new_placement_id'] = $placement->id;
                    $update['after'] = $placement->getAttributes();
                    $this->audit->log('staff.salary_scale_corrected_from_workbook', $person, $update['before'], $update['after'], $source + [
                        'mda_id' => $mda->id, 'source_rows' => $update['source_rows'], 'reason' => $update['reason'],
                        'import_evidence' => $update['import_evidence'],
                    ]);
                }
                unset($update);
            }

            return $report;
        });
    }

    protected function parsePlacement(string $value): ?array
    {
        // Ignore the stray trailing backtick present in the supplied GL14/6 entry.
        if (! preg_match('/^\s*(GL|CH|CM|SG|CONHESS|CONMESS|GRADE\s*LEVEL|SPECIAL\s*GRADE)\s*(\d+)\s*\/\s*(\d+)\s*`?\s*$/i', $value, $parts)) {
            return null;
        }
        $scale = preg_replace('/\s+/', '', strtoupper($parts[1]));
        $scale = match ($scale) {
            'CONHESS' => 'CH', 'CONMESS' => 'CM', 'GRADELEVEL' => 'GL', 'SPECIALGRADE' => 'SG', default => $scale,
        };
        if ((int) $parts[2] < 1 || (int) $parts[3] < 1) {
            return null;
        }

        return ['scale' => $scale, 'level' => (int) $parts[2], 'step' => (int) $parts[3]];
    }
}
