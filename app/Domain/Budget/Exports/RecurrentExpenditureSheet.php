<?php

namespace App\Domain\Budget\Exports;

use App\Domain\Budget\Models\BudgetWorkbook;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RecurrentExpenditureSheet extends DefaultValueBinder implements FromArray, WithCustomStartCell, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    protected const HEADER_ROW = 7;

    protected const FIRST_DATA_ROW = 8;

    public function __construct(
        protected BudgetWorkbook $workbook,
        protected string $reportTitle,
        protected array $group,
        protected string $sheetTitle,
        protected array $notes = [],
    ) {}

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function startCell(): string
    {
        return 'A'.self::HEADER_ROW;
    }

    public function headings(): array
    {
        $year = $this->workbook->year;
        $budgetYear = $this->workbook->movementWorkbook?->budget_year ?? $year + 1;

        return [
            $this->group['scale'] !== '' ? "Grade Level\n".($this->group['grade_label'] ?? $this->group['scale_code'] ?? '') : 'Summary',
            "No. of Staff\nApproved\n{$year}",
            "Actual No. of Staff\nJan - June\n{$year}",
            "Approved Estimate\n{$year}\n(NGN)",
            "Actual Expenditure\nJan - June {$year}\n(NGN)",
            "No. of Staff\nRequired\n{$budgetYear}",
            "Proposed Estimate\n{$budgetYear}\n(NGN)",
        ];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->group['rows'] as $row) {
            $rows[] = [$row['level'], ...$this->figures($row)];
        }

        foreach ($this->summaryRows() as $summary) {
            $rows[] = [$summary['label'], ...$this->figures($summary)];
        }
        foreach ($this->notes as $note) {
            $rows[] = [$note];
        }

        return $rows;
    }

    protected function summaryRows(): array
    {
        return $this->group['summary_rows'] ?? [['label' => 'Total', ...$this->group['totals']]];
    }

    protected function figures(array $row): array
    {
        return [
            $this->integerValue($row['approved_staff'] ?? null),
            $this->integerValue($row['actual_staff'] ?? null),
            $this->moneyValue($row['approved_estimate'] ?? null),
            $this->moneyValue($row['actual_expense'] ?? null),
            $this->integerValue($row['required_staff'] ?? null),
            $this->moneyValue($row['proposed_estimate'] ?? null),
        ];
    }

    protected function integerValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function moneyValue(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function styles(Worksheet $sheet): array
    {
        // Write the report banner separately so spacer rows cannot shift the table.
        $banner = [
            1 => 'Government of Niger State',
            2 => $this->workbook->mda?->name ?? 'MDA',
            3 => $this->reportTitle,
            4 => "Workbook: Budget #{$this->workbook->id} | Movement year: {$this->workbook->year} | Status: ".strtoupper($this->workbook->status),
            5 => $this->group['department'].($this->group['scale'] !== '' ? ' : '.$this->group['scale'] : ''),
        ];
        foreach ($banner as $row => $text) {
            $sheet->setCellValueExplicit('A'.$row, $text, DataType::TYPE_STRING);
            $sheet->mergeCells("A{$row}:G{$row}");
        }

        $headerRow = self::HEADER_ROW;
        $firstDataRow = self::FIRST_DATA_ROW;
        $lastRow = $sheet->getHighestRow();
        $firstTotalRow = $firstDataRow + count($this->group['rows']);
        $lastTableRow = $firstTotalRow + count($this->summaryRows()) - 1;
        $sheet->getStyle("A1:G{$lastRow}")->getFont()->setName('Arial')->setSize(10);
        $sheet->getStyle("A1:G{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        foreach ([1 => 20, 2 => 22, 3 => 30, 4 => 24, 5 => 28, 6 => 8] as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }
        $sheet->getStyle('A1:G5')->getFont()->setBold(true);
        $sheet->getStyle('A1:G4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $sheet->getStyle('A3')->getFont()->setSize(16);
        $sheet->getStyle('A5')->getAlignment()->setWrapText(true);

        foreach (['A' => 32, 'B' => 19, 'C' => 20, 'D' => 23, 'E' => 24, 'F' => 20, 'G' => 25] as $column => $width) {
            $sheet->getColumnDimension($column)->setAutoSize(false)->setWidth($width);
        }

        $sheet->getRowDimension($headerRow)->setRowHeight(48);
        $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F0E8');
        $sheet->getStyle("A{$headerRow}:G{$lastTableRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach (range($firstDataRow, $lastRow) as $row) {
            $sheet->getRowDimension($row)->setRowHeight(22);
        }
        $sheet->getStyle("A{$firstDataRow}:G{$lastRow}")->getFont()->setBold(false);
        $sheet->getStyle("A{$firstDataRow}:G{$lastRow}")->getAlignment()->setWrapText(false);
        $sheet->getStyle("A{$firstDataRow}:A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("B{$firstDataRow}:G{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        foreach (['B', 'C', 'F'] as $column) {
            $sheet->getStyle("{$column}{$firstDataRow}:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        }
        foreach (['D', 'E', 'G'] as $column) {
            $sheet->getStyle("{$column}{$firstDataRow}:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        }
        $sheet->getStyle("A{$firstTotalRow}:G{$lastTableRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$firstTotalRow}:G{$lastTableRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F0E8');
        $sheet->getStyle("A{$firstTotalRow}:A{$lastTableRow}")->getAlignment()->setWrapText(true);
        foreach (range($firstTotalRow, $lastTableRow) as $row) {
            $sheet->getRowDimension($row)->setRowHeight(30);
        }
        foreach ($this->notes as $index => $note) {
            $row = $lastTableRow + $index + 1;
            $sheet->mergeCells("A{$row}:G{$row}");
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getRowDimension($row)->setRowHeight(36);
        }
        $sheet->unfreezePane();
        $sheet->setTopLeftCell('A1');
        $sheet->setSelectedCell('A1');
        $sheet->getSheetView()->setZoomScale(90);
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(1)
            ->setHorizontalCentered(true)
            ->setPrintArea("A1:G{$lastRow}")
            ->setRowsToRepeatAtTopByStartAndEnd(1, $headerRow);
        $sheet->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.35)->setRight(0.35);

        return [];
    }
}
