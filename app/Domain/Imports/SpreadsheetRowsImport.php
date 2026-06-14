<?php

namespace App\Domain\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class SpreadsheetRowsImport implements ToCollection, WithHeadingRow
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function collection(Collection $rows): void
    {
        $this->rows = $rows
            ->map(fn ($row): array => $row->toArray())
            ->filter(fn (array $row): bool => collect($row)->contains(
                fn ($value): bool => $value !== null && trim((string) $value) !== ''
            ))
            ->values()
            ->all();
    }
}
