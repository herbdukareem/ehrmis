<?php

namespace App\Domain\Movement\Exports;

use App\Domain\Movement\Models\MovementWorkbook;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MovementDetailExport implements FromArray, ShouldAutoSize, WithStyles
{
    public function __construct(
        protected MovementWorkbook $workbook,
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
        $lines = $this->workbook->lines()
            ->with(['staff.qualifications', 'currentEmployment.department', 'currentSalaryScale', 'proposedSalaryScale'])
            ->when($this->departmentId !== null, fn ($query) => $query->whereHas('currentEmployment', fn ($employmentQuery) => $employmentQuery->where('department_id', $this->departmentId)))
            ->get()
            ->groupBy(fn ($line): string => $line->currentEmployment?->department?->name ?? 'Unassigned');

        foreach ($lines as $department => $departmentLines) {
            $rows[] = [$department];
            $rows[] = ['S/N', 'CNO', 'Name', 'H. Qual.', 'Current Placement', 'DPA', 'DNP', 'Moving To', 'Eligibility'];

            foreach ($departmentLines->sortBy('staff.full_name')->values() as $index => $line) {
                $qualification = $line->staff?->qualifications->firstWhere('is_highest', true);
                $rows[] = [
                    $index + 1,
                    $line->staff?->legacy_cno ?? $line->staff?->staff_number,
                    $line->staff?->full_name,
                    $qualification?->highest_qualification_name ?? $qualification?->qualification_name,
                    $this->placement($line->currentSalaryScale?->code, $line->current_level, $line->current_step),
                    $line->currentEmployment?->date_last_promotion?->toDateString(),
                    $line->currentEmployment?->next_promotion_date?->toDateString(),
                    $this->placement($line->proposedSalaryScale?->code, $line->proposed_level, $line->proposed_step),
                    $line->eligibility_status,
                ];
            }

            $rows[] = [];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:I1')->getFont()->setBold(true)->setSize(14);

        foreach ($sheet->getRowIterator() as $row) {
            $value = $sheet->getCell('A'.$row->getRowIndex())->getValue();

            if (is_string($value) && $value !== '' && $sheet->getCell('B'.$row->getRowIndex())->getValue() === null) {
                $sheet->mergeCells("A{$row->getRowIndex()}:I{$row->getRowIndex()}");
                $sheet->getStyle("A{$row->getRowIndex()}:I{$row->getRowIndex()}")->getFont()->setBold(true);
            }

            if ($value === 'S/N') {
                $sheet->getStyle("A{$row->getRowIndex()}:I{$row->getRowIndex()}")->getFont()->setBold(true);
            }
        }

        return [];
    }

    protected function placement(?string $scale, ?int $level, ?int $step): ?string
    {
        return $scale ? sprintf('%s %s/%s', $scale, $level ?? '-', $step ?? '-') : null;
    }
}
