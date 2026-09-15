<?php

namespace App\Domain\Staff\Services;

use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Support\LegacyIdentifier;
use App\Domain\Organization\Models\Mda;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StaffWorkbookIdentityMatcher
{
    public function match(Mda $mda, Collection $staff, array $rows): array
    {
        if ($staff->contains(fn ($person) => (int) $person->mda_id !== (int) $mda->id)) {
            throw new InvalidArgumentException('All staff candidates must belong to the selected MDA.');
        }
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
        foreach (LegacyStaffImportRow::query()->where('mda_id', $mda->id)->whereIn('published_staff_id', $staff->pluck('id'))->lazyById(200) as $import) {
            $id = $import->published_staff_id;
            $raw = $import->raw_payload['source_row'] ?? [];
            $add('cno', $raw['cno'] ?? $import->legacy_cno, $id);
            $add('psn', $raw['psn'] ?? $import->legacy_psn, $id);
            $add('name', $raw['name'] ?? $import->full_name, $id);
            $births[$id][$raw['dob'] ?? $import->normalized_payload['date_of_birth'] ?? ''] = true;
        }
        $matched = [];
        $unmatched = [];
        foreach ($rows as $row) {
            if (! empty($row['mda']) && ! in_array($this->key($row['mda']), [$this->key($mda->code), $this->key($mda->name)], true)) {
                throw new InvalidArgumentException('Source row '.$row['row'].' belongs to a different MDA.');
            }
            $nameIds = $indexes['name'][$this->key($row['name'] ?? null)] ?? [];
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
        ksort($matched);

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    protected function key(mixed $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value));
    }
}
