<?php

namespace App\Domain\Movement\Exports;

use App\Domain\Movement\Models\MovementWorkbook;
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

class MovementSummarySheet extends DefaultValueBinder implements FromArray, WithCustomStartCell, WithCustomValueBinder, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    public function __construct(
        protected MovementWorkbook $workbook,
        protected array $department,
        protected string $sheetTitle,
    ) {}

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function startCell(): string
    {
        return 'A7';
    }

    public function headings(): array
    {
        return [
            'S/N', 'Scale', 'Level', "Present No.\nof Staff", "No. of Staff\nMoving",
            "No. of Staff\nRetiring", "No. of Staff\nJoining", "Expected\nTotal",
        ];
    }

    public function array(): array
    {
        $rows = [];
        foreach ($this->department['rows'] as $index => $row) {
            $rows[] = [
                $index + 1, $row['scale'], $row['level'], (int) $row['present_staff'],
                (int) $row['staff_moving'], (int) $row['staff_retiring'], (int) $row['staff_joining'], (int) $row['expected_total'],
            ];
        }
        if (! $rows) {
            return [['No movement summary records match this export.']];
        }
        $rows[] = ['Total', null, null, 0, 0, 0, 0, 0];

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

    public function styles(Worksheet $sheet): array
    {
        // Fixed heading coordinates keep all formatting aligned when a department has no rows.
        $banner = [
            'Government of Niger State',
            $this->workbook->mda?->name ?? 'MDA',
            $this->workbook->year.' Movement Sheet - Department Summary',
            'Workbook #'.$this->workbook->id.' | Budget year: '.($this->workbook->budget_year ?? $this->workbook->year + 1)
                .' | Budget minimum: Step '.($this->workbook->budget_minimum_step ?? '-').' | Status: '.strtoupper($this->workbook->status),
            $this->department['department'],
        ];
        foreach ($banner as $index => $text) {
            $row = $index + 1;
            $sheet->setCellValueExplicit('A'.$row, $text, DataType::TYPE_STRING);
            $sheet->mergeCells("A{$row}:H{$row}");
        }
        $lastRow = $sheet->getHighestRow();
        $sheet->getStyle("A1:H{$lastRow}")->getFont()->setName('Arial')->setSize(10);
        $sheet->getStyle("A1:H{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        foreach ([1 => 20, 2 => 22, 3 => 30, 4 => 28, 5 => 28, 6 => 8, 7 => 42] as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }
        $sheet->getStyle('A1:H5')->getFont()->setBold(true);
        $sheet->getStyle('A1:H5')->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1:H4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A3')->getFont()->setSize(16);
        foreach (['A' => 7, 'B' => 13, 'C' => 10, 'D' => 24, 'E' => 24, 'F' => 24, 'G' => 24, 'H' => 22] as $column => $width) {
            $sheet->getColumnDimension($column)->setAutoSize(false)->setWidth($width);
        }
        $sheet->getStyle('A7:H7')->getFont()->setBold(true);
        $sheet->getStyle('A7:H7')->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A7:H7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F0E8');
        $sheet->getStyle("A7:H{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A8:C{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D8:H{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("D8:H{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        foreach (range(8, $lastRow) as $row) {
            $sheet->getRowDimension($row)->setRowHeight(22);
        }

        if ($this->department['rows']) {
            $sheet->mergeCells("A{$lastRow}:C{$lastRow}");
            foreach (range('D', 'H') as $column) {
                $sheet->setCellValueExplicit($column.$lastRow, '=SUM('.$column.'8:'.$column.($lastRow - 1).')', DataType::TYPE_FORMULA);
            }
            $sheet->getStyle("A{$lastRow}:H{$lastRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$lastRow}:H{$lastRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F0E8');
            $sheet->getRowDimension($lastRow)->setRowHeight(26);
        } else {
            $sheet->mergeCells('A8:H8');
            $sheet->getRowDimension(8)->setRowHeight(28);
        }
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0)
            ->setHorizontalCentered(true)->setPrintArea("A1:H{$lastRow}")->setRowsToRepeatAtTopByStartAndEnd(1, 7);
        $sheet->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.35)->setRight(0.35);
        $sheet->getHeaderFooter()->setOddFooter('&LMovement #'.$this->workbook->id.'&RPage &P');
        $sheet->setShowGridlines(false);
        $sheet->unfreezePane();
        $sheet->setTopLeftCell('A1');
        $sheet->setSelectedCell('A1');
        $sheet->getSheetView()->setZoomScale(90);

        return [];
    }
}
