<?php

namespace App\Domain\ServiceReporting\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ArrayReportExport implements FromArray, ShouldAutoSize
{
    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function __construct(protected array $rows)
    {
    }

    public function array(): array
    {
        return $this->rows;
    }
}
