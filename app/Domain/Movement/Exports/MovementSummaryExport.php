<?php

namespace App\Domain\Movement\Exports;

use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementDepartmentSummaryService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MovementSummaryExport implements FromArray, ShouldAutoSize, WithStyles
{
    public function __construct(
        protected MovementWorkbook $workbook,
        protected MovementDepartmentSummaryService $summaryService,
        protected ?int $departmentId = null,
    ) {
    }

    public function array(): array
    {
        $rows = [
            [$this->workbook->name ?? "{$this->workbook->year} Movement Sheet"],
            ['Movement year', $this->workbook->year, 'Budget year', $this->workbook->budget_year, 'Budget minimum step', $this->workbook->budget_minimum_step],
            [],
        ];

        foreach ($this->summaryService->summarize($this->workbook) as $department) {
            if ($this->departmentId !== null && $department['department_id'] !== $this->departmentId) {
                continue;
            }

            $rows[] = [$department['department']];
            $rows[] = ['S/N', 'Scale', 'Level', 'Present No. of Staff', 'No. of Staff Moving', 'No. of Staff Joining', 'Expected Total'];

            foreach ($department['rows'] as $index => $row) {
                $rows[] = [
                    $index + 1,
                    $row['scale'],
                    $row['level'],
                    $row['present_staff'],
                    $row['staff_moving'],
                    $row['staff_joining'],
                    $row['expected_total'],
                ];
            }

            $rows[] = [];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:G1')->getFont()->setBold(true)->setSize(14);

        foreach ($sheet->getRowIterator() as $row) {
            $value = $sheet->getCell('A'.$row->getRowIndex())->getValue();

            if (is_string($value) && $value !== '' && $sheet->getCell('B'.$row->getRowIndex())->getValue() === null) {
                $sheet->mergeCells("A{$row->getRowIndex()}:G{$row->getRowIndex()}");
                $sheet->getStyle("A{$row->getRowIndex()}:G{$row->getRowIndex()}")->getFont()->setBold(true);
            }

            if ($value === 'S/N') {
                $sheet->getStyle("A{$row->getRowIndex()}:G{$row->getRowIndex()}")->getFont()->setBold(true);
            }
        }

        return [];
    }
}
