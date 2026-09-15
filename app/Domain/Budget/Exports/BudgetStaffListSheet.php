<?php

namespace App\Domain\Budget\Exports;

use App\Support\ReportFormatter;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BudgetStaffListSheet extends BudgetReportSheet
{
    protected const WIDTHS = ['A' => 6, 'B' => 28, 'C' => 6, 'D' => 12, 'E' => 15, 'F' => 19, 'G' => 12, 'H' => 12, 'I' => 18, 'J' => 13, 'K' => 13, 'L' => 14, 'M' => 14, 'N' => 28];

    protected const HEADINGS = ['S/N', 'Name', 'Sex', 'DOB', 'LGA', 'Qualifications', 'DFA', 'DPA', 'Rank', "Level /\nStep", 'PSN', 'File No.', 'CNO', 'Remark'];

    public function array(): array
    {
        $rows = [];

        foreach ($this->sections() as $section) {
            $rows[] = [$section['title']];
            $rows[] = self::HEADINGS;

            foreach ($section['rows'] as $row) {
                $rows[] = $this->staffRow($row);
            }
        }

        return $rows ?: [['No staff match this report.']];
    }

    protected function staffRow(array $row): array
    {
        return [
            (int) $row['sn'], $row['name'], $row['sex'], $this->excelDate($row['dob']),
            $row['lga'], $row['qualification'], $this->excelDate($row['dfa']), $this->excelDate($row['dpa']),
            $row['rank'], $row['level_step'],
            $row['psn'] === null ? null : (string) $row['psn'],
            $row['file_no'] === null ? null : (string) $row['file_no'],
            $row['cno'] === null ? null : (string) $row['cno'],
            $row['remark'],
        ];
    }

    protected function excelDate(?string $date): ?float
    {
        return $date ? Date::PHPToExcel(new DateTimeImmutable($date)) : null;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();
        $sections = $this->sections();
        $this->prepareSheet($sheet, 'N', self::WIDTHS, $sections->isEmpty() ? 7 : 8);
        $sheet->getStyle("A7:N{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A8:N{$lastRow}")->getAlignment()->setWrapText(true);

        if ($sections->isNotEmpty()) {
            $firstHeaderRow = null;
            $rowNumber = 7;

            foreach ($sections as $section) {
                $sectionRow = $rowNumber++;
                $headerRow = $rowNumber++;
                $firstHeaderRow ??= $headerRow;

                $sheet->mergeCells("A{$sectionRow}:N{$sectionRow}");
                $sheet->getRowDimension($sectionRow)->setRowHeight(24);
                $sheet->getStyle("A{$sectionRow}:N{$sectionRow}")->getFont()->setBold(true);
                $sheet->getStyle("A{$sectionRow}:N{$sectionRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("A{$sectionRow}:N{$sectionRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8EDF3');

                $this->shadeHeading($sheet, "A{$headerRow}:N{$headerRow}");
                $sheet->getRowDimension($headerRow)->setRowHeight(36);

                foreach ($section['rows'] as $row) {
                    $lineCount = 1;
                    foreach (['B' => 'name', 'E' => 'lga', 'F' => 'qualification', 'I' => 'rank', 'K' => 'psn', 'L' => 'file_no', 'M' => 'cno', 'N' => 'remark'] as $column => $key) {
                        $capacity = (int) floor(self::WIDTHS[$column] * 0.8);
                        $lines = array_sum(array_map(fn (string $part): int => max(1, (int) ceil(mb_strwidth($part) / $capacity)), preg_split('/\R/u', (string) $row[$key])));
                        $lineCount = max($lineCount, $lines);
                    }

                    foreach (['A', 'C', 'D', 'G', 'H', 'J'] as $column) {
                        $sheet->getStyle("{$column}{$rowNumber}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }
                    foreach (['D', 'G', 'H'] as $column) {
                        $sheet->getStyle("{$column}{$rowNumber}")->getNumberFormat()->setFormatCode(ReportFormatter::EXCEL_DATE_FORMAT);
                    }
                    foreach (['K', 'L', 'M'] as $column) {
                        $sheet->getStyle("{$column}{$rowNumber}")->getNumberFormat()->setFormatCode('@');
                    }
                    $sheet->getRowDimension($rowNumber)->setRowHeight(16 * $lineCount + 8);
                    $rowNumber++;
                }
            }

            if ($firstHeaderRow !== null) {
                $sheet->setAutoFilter("A{$firstHeaderRow}:N{$lastRow}");
            }
        } else {
            $sheet->mergeCells('A7:N7');
            $sheet->getRowDimension(7)->setRowHeight(28);
        }
        $this->resetView($sheet);

        return [];
    }

    protected function sections(): Collection
    {
        return collect($this->group['sections'] ?? [])->filter(fn (array $section): bool => count($section['rows'] ?? []) > 0)->values();
    }
}
