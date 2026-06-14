<?php

namespace App\Domain\Imports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SpreadsheetTemplateExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function __construct(
        protected array $headings,
        protected array $exampleRows,
    ) {
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->exampleRows;
    }
}
