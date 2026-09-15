<?php

namespace App\Domain\Movement\Exports;

use App\Domain\Movement\Models\MovementWorkbook;
use App\Support\ReportFormatter;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MovementDetailExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithStyles
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
            ->filter(fn ($line): bool => $this->shouldExportLine($line))
            ->groupBy(fn ($line): string => $line->currentEmployment?->department?->name ?? 'Unassigned');

        foreach ($lines as $department => $departmentLines) {
            $rows[] = [$department];
            $rows[] = ['S/N', 'CNO', 'Name', 'H. Qual.', 'Current Placement', 'DFA', 'DPA', 'DNP', 'Moving To', 'Special Movement', 'Eligibility'];

            foreach ($departmentLines->sortBy('staff.full_name')->values() as $index => $line) {
                $qualification = $line->staff?->qualifications->firstWhere('is_highest', true);
                $isContractStaff = $this->effectiveContractStaff($line);

                $rows[] = [
                    $index + 1,
                    ReportFormatter::cno($line->staff?->legacy_cno, $line->staff?->staff_number),
                    $line->staff?->full_name,
                    $qualification?->highest_qualification_name ?? $qualification?->qualification_name,
                    $this->placement($line->currentSalaryScale?->code, $line->current_level, $line->current_step),
                    $line->currentEmployment?->date_first_appointment ? Date::PHPToExcel($line->currentEmployment->date_first_appointment) : null,
                    $line->currentEmployment?->date_last_promotion ? Date::PHPToExcel($line->currentEmployment->date_last_promotion) : null,
                    $line->currentEmployment?->next_promotion_date ? Date::PHPToExcel($line->currentEmployment->next_promotion_date) : null,
                    $this->placement($line->proposedSalaryScale?->code, $line->proposed_level, $line->proposed_step),
                    $line->is_special_movement ? 'Yes' : 'No',
                    $isContractStaff ? 'contract' : $line->eligibility_status,
                ];
            }

            $rows[] = [];
        }

        return $rows;
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function columnFormats(): array
    {
        return array_fill_keys(['F', 'G', 'H'], ReportFormatter::EXCEL_DATE_FORMAT);
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:K1')->getFont()->setBold(true)->setSize(14);
        // This cell shares the DFA column but contains the budget minimum step.
        $sheet->getStyle('F2')->getNumberFormat()->setFormatCode('General');

        foreach ($sheet->getRowIterator() as $row) {
            $value = $sheet->getCell('A'.$row->getRowIndex())->getValue();

            if (is_string($value) && $value !== '' && $sheet->getCell('B'.$row->getRowIndex())->getValue() === null) {
                $sheet->mergeCells("A{$row->getRowIndex()}:K{$row->getRowIndex()}");
                $sheet->getStyle("A{$row->getRowIndex()}:K{$row->getRowIndex()}")->getFont()->setBold(true);
            }

            if ($value === 'S/N') {
                $sheet->getStyle("A{$row->getRowIndex()}:K{$row->getRowIndex()}")->getFont()->setBold(true);
            }
        }

        return [];
    }

    protected function placement(?string $scale, ?int $level, ?int $step): ?string
    {
        return $scale ? sprintf('%s %s/%s', $scale, $level ?? '-', $step ?? '-') : null;
    }

    protected function shouldExportLine($line): bool
    {
        return in_array($line->retirement_status, ['active', 'retiring'], true)
            || $this->effectiveContractStaff($line);
    }

    protected function effectiveContractStaff($line): bool
    {
        return (bool) ($line->staff?->is_contract_staff ?? $line->is_contract_staff);
    }
}
