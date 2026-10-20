<?php

namespace App\Domain\Procurement\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class ProcurementReportExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithEvents, WithStrictNullComparison, WithTitle
{
    private int $headerRow = 1;

    public function __construct(private array $report) {}

    public function title(): string
    {
        return $this->report['title'];
    }

    public function array(): array
    {
        $rows = [[$this->report['title']]];
        foreach ($this->report['metadata'] as $label => $value) {
            $rows[] = [$label, $value];
        }
        $rows[] = ['Basis', $this->report['basis']];
        foreach ($this->report['summary']['currencies'] as $currency => $totals) {
            foreach ($totals as $key => $value) {
                $rows[] = [ucwords(str_replace('_', ' ', $key)).' ('.$currency.')', $this->numeric($value)];
            }
        }
        foreach ($this->report['summary']['counts'] as $key => $value) {
            $rows[] = [ucwords(str_replace('_', ' ', $key)), $value];
        }
        if ($this->report['type'] === 'procurement-tracking') {
            $rows[] = ['Mean line delivery (%)', $this->report['summary']['delivery_progress']];
        }
        $rows[] = [];
        $this->headerRow = count($rows) + 1;
        $rows[] = array_column($this->report['columns'], 'label');
        foreach ($this->report['rows'] as $record) {
            $rows[] = array_map(fn ($column) => in_array($column['type'], ['money', 'number', 'percent']) ? $this->numeric($record[$column['key']] ?? 0) : ($record[$column['key']] ?? ''), $this->report['columns']);
        }

        return $rows;
    }

    private function numeric(mixed $value): mixed
    {
        // Excel supports only 15 significant digits; retain larger amounts as exact text.
        return strlen(ltrim(str_replace(['.', '-'], '', (string) $value), '0')) > 15 ? (string) $value : (float) $value;
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $last = $sheet->getHighestColumn();
            $bottom = $sheet->getHighestRow();
            $sheet->getDefaultColumnDimension()->setWidth(24);
            $sheet->getColumnDimension('A')->setWidth(35);
            $sheet->getColumnDimension('B')->setWidth(40);
            $sheet->freezePane('B'.($this->headerRow + 1));
            $sheet->setAutoFilter('A'.$this->headerRow.':'.$last.$bottom);
            $sheet->getStyle('A1:'.$last.$bottom)->getAlignment()->setWrapText(true)->setVertical('top');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A'.$this->headerRow.':'.$last.$this->headerRow)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '134E4A']],
            ]);
            foreach ($this->report['columns'] as $index => $column) {
                if ($column['type'] === 'money') {
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($index + 1).($this->headerRow + 1).':'.Coordinate::stringFromColumnIndex($index + 1).max($bottom, $this->headerRow + 1))->getNumberFormat()->setFormatCode('#,##0.00');
                }
            }
            $basisRow = count($this->report['metadata']) + 2;
            if ($this->headerRow > $basisRow + 2 && $this->report['summary']['currencies']) {
                $sheet->getStyle('B'.($basisRow + 1).':B'.($this->headerRow - 2))->getNumberFormat()->setFormatCode('#,##0.00');
            }
            $sheet->mergeCells('B'.$basisRow.':'.$last.$basisRow);
            $sheet->getRowDimension($basisRow)->setRowHeight(44);
            $sheet->getRowDimension($this->headerRow)->setRowHeight(30);
        }];
    }
}
