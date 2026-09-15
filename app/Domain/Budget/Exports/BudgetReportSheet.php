<?php

namespace App\Domain\Budget\Exports;

use App\Domain\Budget\Models\BudgetWorkbook;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

abstract class BudgetReportSheet extends DefaultValueBinder implements FromArray, WithCustomStartCell, WithCustomValueBinder, WithStrictNullComparison, WithStyles, WithTitle
{
    public function __construct(
        protected BudgetWorkbook $workbook,
        protected string $reportTitle,
        protected array $group,
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

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            // Staff identifiers retain leading zeros; imported text never becomes a formula.
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    protected function prepareSheet(Worksheet $sheet, string $lastColumn, array $widths, int $repeatThrough): void
    {
        $lastRow = $sheet->getHighestRow();
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getFont()->setName('Arial')->setSize(10);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $banner = [
            'Government of Niger State',
            $this->workbook->mda?->name ?? 'MDA',
            $this->reportTitle,
            "Workbook: Budget #{$this->workbook->id} | Movement year: {$this->workbook->year} | Status: ".strtoupper($this->workbook->status),
            $this->group['department'],
        ];
        foreach ($banner as $index => $text) {
            $row = $index + 1;
            $sheet->setCellValueExplicit('A'.$row, $text, DataType::TYPE_STRING);
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        }
        foreach ([1 => 20, 2 => 22, 3 => 30, 4 => 24, 5 => 28, 6 => 8] as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }
        $sheet->getStyle('A1:'.$lastColumn.'5')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$lastColumn.'5')->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1:'.$lastColumn.'4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A3')->getFont()->setSize(16);
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setAutoSize(false)->setWidth($width);
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0)
            ->setHorizontalCentered(true)->setPrintArea("A1:{$lastColumn}{$lastRow}")
            ->setRowsToRepeatAtTopByStartAndEnd(1, $repeatThrough);
        $sheet->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.35)->setRight(0.35);
        $sheet->getHeaderFooter()->setOddFooter('&L'.$this->workbook->mda?->code.' | Budget #'.$this->workbook->id.'&RPage &P of &N');
        $sheet->setShowGridlines(false);
    }

    protected function shadeHeading(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F0E8');
    }

    protected function resetView(Worksheet $sheet): void
    {
        $sheet->unfreezePane();
        $sheet->setTopLeftCell('A1');
        $sheet->setSelectedCell('A1');
        $sheet->getSheetView()->setZoomScale(85);
    }
}
