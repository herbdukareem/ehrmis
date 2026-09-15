<?php

namespace App\Domain\Budget\Exports;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BudgetManpowerDistributionSheet extends BudgetReportSheet
{
    protected array $sexSections = [];

    protected ?array $occupationSection = null;

    public function array(): array
    {
        $rows = [];
        $this->sexSections = [];
        $this->occupationSection = null;
        $sections = collect($this->group['sections'] ?? [])->filter(fn (array $section): bool => count($section['rows'] ?? []) > 0)->values();

        foreach ($sections as $section) {
            if ($rows) {
                $rows[] = [null];
            }

            $start = count($rows) + 7;
            $rows[] = ['NO. OF STAFF BY SEX'];
            $rows[] = [$section['scale_code'], 'NO. OF STAFF', null, null];
            $rows[] = [null, 'MALE', 'FEMALE', 'GRAND TOTAL'];

            foreach ($section['rows'] as $row) {
                $rows[] = [$row['label'], (int) $row['male'], (int) $row['female'], (int) $row['total']];
            }

            $rows[] = ['S/GRADE', 0, 0, 0];
            $rows[] = ['G/TOTAL', (int) $section['totals']['male'], (int) $section['totals']['female'], (int) $section['totals']['total']];
            $this->sexSections[] = ['start' => $start, 'header' => $start + 1, 'subheader' => $start + 2, 'first' => $start + 3, 'total' => count($rows) + 6];
        }

        $occupationRows = collect($this->group['occupation_rows'] ?? []);
        if ($occupationRows->isNotEmpty()) {
            if ($rows) {
                $rows[] = [null];
            }

            $start = count($rows) + 7;
            $rows[] = ['STAFF STRENGTH BY PROFESSIONALISM'];
            $rows[] = [null, 'NO. OF STAFF', null, null];
            $rows[] = ['OCCUPATION', 'MALE', 'FEMALE', 'TOTAL'];

            foreach ($occupationRows as $row) {
                $rows[] = [$row['occupation'], (int) $row['male'], (int) $row['female'], (int) $row['total']];
            }

            $rows[] = ['TOTAL', (int) ($this->group['occupation_totals']['male'] ?? 0), (int) ($this->group['occupation_totals']['female'] ?? 0), (int) ($this->group['occupation_totals']['total'] ?? 0)];
            $this->occupationSection = ['start' => $start, 'header' => $start + 1, 'subheader' => $start + 2, 'first' => $start + 3, 'total' => count($rows) + 6];
        }

        return $rows ?: [['No staff match this report.']];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();
        $this->prepareSheet($sheet, 'D', ['A' => 28, 'B' => 13, 'C' => 13, 'D' => 16], 9);

        foreach ($this->sexSections as $section) {
            $this->styleSection($sheet, $section, true, true);
        }

        if ($this->occupationSection !== null) {
            $this->styleSection($sheet, $this->occupationSection, false, false);
        }

        if (! $this->sexSections && $this->occupationSection === null) {
            $sheet->mergeCells('A7:D7');
            $sheet->getRowDimension(7)->setRowHeight(28);
        }

        $sheet->getStyle("A7:D{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A7:D{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("B7:D{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        $this->resetView($sheet);

        return [];
    }

    protected function styleSection(Worksheet $sheet, array $section, bool $setBreak, bool $hasSubGradeRow): void
    {
        $start = $section['start'];
        $header = $section['header'];
        $subheader = $section['subheader'];
        $first = $section['first'];
        $total = $section['total'];

        $sheet->mergeCells("A{$start}:D{$start}");
        $sheet->mergeCells("B{$header}:D{$header}");
        $sheet->getRowDimension($start)->setRowHeight(24);
        $sheet->getStyle("A{$start}:D{$subheader}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->shadeHeading($sheet, "A{$start}:D{$subheader}");
        $sheet->getStyle("A{$first}:D{$total}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("B{$first}:D{$total}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("A{$total}:D{$total}")->getFont()->setBold(true);
        $sheet->getStyle("A{$total}:D{$total}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8EDF3');

        foreach (range($first, $total) as $row) {
            $sheet->getRowDimension($row)->setRowHeight(22);
        }

        if ($setBreak && $start > 7) {
            $sheet->setBreak('A'.($start - 1), Worksheet::BREAK_ROW);
        }

        foreach (['B', 'C', 'D'] as $column) {
            $lastSummedRow = $hasSubGradeRow ? $total - 2 : $total - 1;
            $sheet->setCellValueExplicit($column.$total, '=SUM('.$column.$first.':'.$column.$lastSummedRow.')', DataType::TYPE_FORMULA);
        }
    }
}
