<?php

namespace App\Domain\Staff\Services;

use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Support\LegacyIdentifier;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffPersonalDetail;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class StaffPersonalDetailsImportService
{
    public function __construct(protected AuditLogService $audit) {}

    public function import(Mda $mda, User $actor, array $rows, array $source, bool $dryRun = false): array
    {
        // This maintenance import checks file-number reservations across the system.
        abort_unless($actor->hasGlobalMdaAccess() && $actor->can('update-staff'), 403);

        return DB::transaction(function () use ($mda, $actor, $rows, $source, $dryRun): array {
            $mdaQuery = Mda::query()->visibleToUser($actor)->orderBy('id');
            if (! $dryRun) {
                $mdaQuery->lockForUpdate();
            }
            $mdaIds = $mdaQuery->pluck('id');
            abort_unless($mdaIds->contains($mda->id), 403);
            $staff = Staff::query()->where('mda_id', $mda->id)->with('personalDetail')->orderBy('id')->get()->keyBy('id');
            $indexes = ['cno' => [], 'psn' => [], 'name' => []];
            $births = [];
            $add = function (string $type, mixed $value, int $id) use (&$indexes): void {
                $key = $this->key(LegacyIdentifier::normalize($value));
                if ($key !== '') {
                    $indexes[$type][$key][$id] = $id;
                }
            };
            foreach ($staff as $person) {
                $add('cno', $person->legacy_cno, $person->id);
                $add('cno', $person->staff_number, $person->id);
                $add('psn', $person->legacy_psn, $person->id);
                $add('name', $person->full_name, $person->id);
                $births[$person->id][$person->date_of_birth?->format('Y-m-d') ?? ''] = true;
            }
            foreach (LegacyStaffImportRow::query()->where('mda_id', $mda->id)->whereIn('published_staff_id', $staff->keys())->lazyById(200) as $import) {
                $id = $import->published_staff_id;
                $raw = $import->raw_payload['source_row'] ?? [];
                $add('cno', $raw['cno'] ?? $import->legacy_cno, $id);
                $add('psn', $raw['psn'] ?? $import->legacy_psn, $id);
                $add('name', $raw['name'] ?? $import->full_name, $id);
                $births[$id][$raw['dob'] ?? $import->normalized_payload['date_of_birth'] ?? ''] = true;
            }

            $matched = [];
            $unmatched = [];
            $reserved = [];
            foreach (StaffPersonalDetail::query()->whereHas('staff', fn ($q) => $q->withTrashed()->whereIn('mda_id', $mdaIds))->pluck('file_no') as $number) {
                $reserved[strtoupper(trim((string) $number))] = true;
            }
            foreach ($rows as $row) {
                if (! empty($row['mda']) && ! in_array($this->key($row['mda']), [$this->key($mda->code), $this->key($mda->name)], true)) {
                    throw new InvalidArgumentException('Source row '.$row['row'].' belongs to a different MDA.');
                }
                foreach (['file_no', 'lga'] as $field) {
                    if (mb_strlen((string) ($row[$field] ?? '')) > 255) {
                        throw new InvalidArgumentException('Source row '.$row['row'].' has an oversized '.$field.' value.');
                    }
                    $row[$field] = LegacyIdentifier::normalize($row[$field] ?? null);
                }
                $reserved[strtoupper((string) $row['file_no'])] = true;
                $nameIds = $indexes['name'][$this->key($row['name'])] ?? [];
                $identifierIds = ($indexes['cno'][$this->key($row['cno'] ?? null)] ?? []) + ($indexes['psn'][$this->key($row['psn'] ?? null)] ?? []);
                $ids = array_intersect_key($nameIds, $identifierIds);
                if (count($ids) !== 1) {
                    $ids = array_filter($nameIds, fn ($id) => ! empty($row['dob']) && isset($births[$id][$row['dob']]));
                }
                if (count($ids) !== 1) {
                    $unmatched[] = $row;

                    continue;
                }
                $matched[array_values($ids)[0]][] = $row;
            }

            $updates = [];
            $conflicts = [];
            $resolvedDuplicates = [];
            $generated = 0;
            $sequence = 1;
            $missingLga = 0;
            ksort($matched);
            foreach ($matched as $id => $group) {
                $person = $staff[$id];
                Gate::forUser($actor)->authorize('update', $person);
                $chosen = $group;
                if ($this->hasConflict($group)) {
                    $chosen = array_values(array_filter($group, fn ($row) => ! empty($row['dob']) && $row['dob'] === $person->date_of_birth?->format('Y-m-d')));
                    if (! $chosen || $this->hasConflict($chosen)) {
                        $conflicts[] = ['staff_id' => $id, 'staff_number' => $person->staff_number, 'name' => $person->full_name, 'source_rows' => $group];

                        continue;
                    }
                    $resolvedDuplicates[] = ['staff_id' => $id, 'used_rows' => array_column($chosen, 'row'), 'other_rows' => array_values(array_diff(array_column($group, 'row'), array_column($chosen, 'row'))), 'reason' => 'Matches current staff date of birth'];
                }
                $file = $this->values($chosen, 'file_no')[0] ?? LegacyIdentifier::normalize($person->personalDetail?->file_no);
                $lga = $this->values($chosen, 'lga')[0] ?? LegacyIdentifier::normalize($person->personalDetail?->lga);
                $isGenerated = $file === null;
                if ($isGenerated) {
                    do {
                        if ($sequence > 999) {
                            throw new InvalidArgumentException('Not enough unused G001-G999 file numbers. No changes were saved.');
                        }
                        $file = sprintf('G%03d', $sequence++);
                    } while (isset($reserved[$file]));
                    $reserved[$file] = true;
                    $generated++;
                }
                $missingLga += $lga === null ? 1 : 0;
                $after = ['file_no' => $file, 'lga' => $lga];
                $before = ['file_no' => $person->personalDetail?->file_no, 'lga' => $person->personalDetail?->lga];
                if ($before !== $after) {
                    $updates[] = ['staff_id' => $id, 'staff_number' => $person->staff_number, 'name' => $person->full_name, 'before' => $before, 'after' => $after, 'generated' => $isGenerated, 'source_rows' => array_column($chosen, 'row')];
                }
            }

            if (! $dryRun) {
                foreach ($updates as $update) {
                    $person = $staff[$update['staff_id']];
                    $person->personalDetail()->updateOrCreate(['staff_id' => $person->id], $update['after']);
                    $this->audit->log('staff.personal_details_imported', $person, $update['before'], $update['after'], $source + [
                        'mda_id' => $mda->id, 'source_rows' => $update['source_rows'], 'generated_file_number' => $update['generated'],
                    ]);
                }
            }

            return [
                'dry_run' => $dryRun, 'mda' => $mda->code, 'source' => $source,
                'source_rows' => count($rows), 'matched_staff' => count($matched), 'updated' => count($updates),
                'generated_file_numbers' => $generated, 'missing_lga' => $missingLga,
                'unmatched_source_rows' => $unmatched, 'conflicts' => $conflicts, 'resolved_duplicates' => $resolvedDuplicates,
                'staff_not_in_source' => $staff->except(array_keys($matched))->map(fn ($person) => $person->only(['id', 'staff_number', 'full_name']))->values()->all(),
                'updates' => $updates,
            ];
        });
    }

    protected function values(array $rows, string $field): array
    {
        return array_values(array_unique(array_map(fn ($value) => $field === 'lga' ? strtoupper(trim($value)) : $value,
            array_filter(array_column($rows, $field), fn ($value) => $value !== null))));
    }

    protected function hasConflict(array $rows): bool
    {
        return count($this->values($rows, 'file_no')) > 1 || count($this->values($rows, 'lga')) > 1;
    }

    protected function key(mixed $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value));
    }
}
