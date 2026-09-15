<?php

namespace App\Domain\Budget\Exports;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BudgetQualificationSheet extends BudgetReportSheet
{
    protected array $sections = [];

    protected function qualifications(): Collection
    {
        return collect($this->group['sections'])->flatMap(fn (array $section) => $section['qualifications'])->unique()->values();
    }

    public function array(): array
    {
        $rows = [];
        $this->sections = [];
        $qualifications = $this->qualifications();
        foreach ($this->group['sections'] as $section) {
            if ($rows) {
                $rows[] = [null];
            }
            $start = count($rows) + 7;
            $rows[] = ['Salary scale: '.$section['scale']];
            $top = ['Level'];
            $bottom = [null];
            foreach ($qualifications as $qualification) {
                array_push($top, $qualification, null);
                array_push($bottom, 'Male', 'Female');
            }
            $rows[] = [...$top, 'Total'];
            $rows[] = [...$bottom, null];
            foreach ($section['rows'] as $row) {
                $values = [(int) $row['level']];
                foreach ($qualifications as $qualification) {
                    $index = collect($section['qualifications'])->search($qualification, true);
                    $cell = $index === false ? [] : collect($row['cells'])->get($index, []);
                    array_push($values, (int) ($cell['male'] ?? 0), (int) ($cell['female'] ?? 0));
                }
                $rows[] = [...$values, (int) $row['total']];
            }
            $rows[] = ['Total', ...array_fill(0, $qualifications->count() * 2 + 1, 0)];
            $this->sections[] = ['start' => $start, 'first' => $start + 3, 'total' => count($rows) + 6];
        }

        return $rows ?: [['No staff match this report.']];
    }

    public function styles(Worksheet $sheet): array
    {
        $qualificationCount = $this->qualifications()->count();
        $columnCount = max(8, $qualificationCount * 2 + 2);
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $widths = [];
        foreach (range(1, $columnCount) as $index) {
            $widths[Coordinate::stringFromColumnIndex($index)] = $index === 1 || $index === $columnCount ? 10 : 9;
        }
        $this->prepareSheet($sheet, $lastColumn, $widths, 5);
        foreach ($this->sections as $index => $section) {
            $start = $section['start'];
            $header = $start + 1;
            $subheader = $start + 2;
            $first = $section['first'];
            $total = $section['total'];
            $tableLast = Coordinate::stringFromColumnIndex($qualificationCount * 2 + 2);
            $sheet->mergeCells("A{$start}:{$lastColumn}{$start}");
            $sheet->getRowDimension($start)->setRowHeight(28);
            $sheet->getStyle('A'.$start)->getFont()->setBold(true);
            $sheet->mergeCells("A{$header}:A{$subheader}");
            $sheet->mergeCells("{$tableLast}{$header}:{$tableLast}{$subheader}");
            for ($qualificationIndex = 0; $qualificationIndex < $qualificationCount; $qualificationIndex++) {
                $left = Coordinate::stringFromColumnIndex($qualificationIndex * 2 + 2);
                $right = Coordinate::stringFromColumnIndex($qualificationIndex * 2 + 3);
                $sheet->mergeCells("{$left}{$header}:{$right}{$header}");
            }
            $this->shadeHeading($sheet, "A{$header}:{$tableLast}{$subheader}");
            $sheet->getRowDimension($header)->setRowHeight(36);
            $sheet->getRowDimension($subheader)->setRowHeight(24);
            $sheet->getStyle("A{$header}:{$tableLast}{$total}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            if ($first < $total) {
                $sheet->getStyle("A{$first}:{$tableLast}{$total}")->getNumberFormat()->setFormatCode('#,##0');
            }
            $sheet->getStyle("A{$first}:{$tableLast}{$total}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            foreach (range($first, $total) as $row) {
                $sheet->getRowDimension($row)->setRowHeight(22);
            }
            for ($column = 2; $column <= $qualificationCount * 2 + 2; $column++) {
                $letter = Coordinate::stringFromColumnIndex($column);
                if ($first < $total) {
                    $sheet->setCellValueExplicit($letter.$total, '=SUM('.$letter.$first.':'.$letter.($total - 1).')', DataType::TYPE_FORMULA);
                }
            }
            $this->shadeHeading($sheet, "A{$total}:{$tableLast}{$total}");
            if ($index > 0) {
                // Excel breaks after this row, so the scale heading stays with its table.
                $sheet->setBreak('A'.($start - 1), Worksheet::BREAK_ROW);
                $sheet->getRowDimension($start - 1)->setRowHeight(8);
            }
        }
        if (! $this->sections) {
            $sheet->mergeCells('A7:'.$lastColumn.'7');
            $sheet->getRowDimension(7)->setRowHeight(28);
        }
        $this->resetView($sheet);

        return [];
    }
}
