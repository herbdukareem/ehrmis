<?php

namespace App\Domain\Staff\Exports;

use App\Domain\Staff\Services\StaffListReportService;
use App\Models\User;
use App\Support\ReportFormatter;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class StaffListReportExport extends DefaultValueBinder implements FromGenerator, WithColumnFormatting, WithCustomValueBinder, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    protected Collection $types;

    public function __construct(protected StaffListReportService $report, protected User $user, protected array $filters)
    {
        // PhpSpreadsheet retains worksheet cells even when source rows are streamed.
        // Give this export a bounded allowance without reducing a higher server limit.
        $memory = (string) config('reports.staff_list_export_memory_limit', '512M');
        $current = (string) ini_get('memory_limit');
        if ($current !== '-1' && ini_parse_quantity($memory) > ini_parse_quantity($current)) {
            ini_set('memory_limit', $memory);
        }
        if ((int) ini_get('max_execution_time') > 0) {
            set_time_limit(max((int) ini_get('max_execution_time'), (int) config('reports.staff_list_export_timeout', 120)));
        }
        $this->types = $report->allowanceTypes();
    }

    public function title(): string
    {
        return 'Staff list';
    }

    public function headings(): array
    {
        $headings = array_values(StaffListReportService::COLUMNS);
        foreach ($this->types as $type) {
            $headings[] = $type->name.' eligibility';
            $headings[] = $type->name.' (NGN)';
        }
        $headings[] = 'Calculation note';

        return $headings;
    }

    public function generator(): Generator
    {
        // Export all matching staff, irrespective of the preview page.
        foreach ($this->report->query($this->user, $this->filters)->lazy(250) as $staff) {
            $record = $this->report->row($staff, $this->types);
            foreach (StaffListReportService::DATE_COLUMNS as $key) {
                $record[$key] = $record[$key] ? Date::PHPToExcel(new DateTimeImmutable($record[$key])) : null;
            }
            $row = array_map(fn ($key) => $record[$key], array_keys(StaffListReportService::COLUMNS));
            foreach ($record['allowances'] as $allowance) {
                $row[] = $allowance['eligibility'];
                $row[] = $allowance['amount'];
            }
            $row[] = $record['basic_salary'] === null
                ? 'Salary rate unavailable'
                : ($record['unpriced_allowances']
                    ? 'Partial total; excludes unpriced allowances: '.implode(', ', $record['unpriced_allowances'])
                    : '');
            yield $row;
        }
    }

    public function bindValue(Cell $cell, $value): bool
    {
        // Preserve leading zeros in identifiers and keep imported text out of formulas.
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function columnFormats(): array
    {
        $columns = ['AA' => '#,##0.00', 'AB' => '#,##0.00', 'AC' => '#,##0.00'];
        foreach (array_keys(StaffListReportService::COLUMNS) as $index => $key) {
            if (in_array($key, StaffListReportService::DATE_COLUMNS, true)) {
                $columns[Coordinate::stringFromColumnIndex($index + 1)] = ReportFormatter::EXCEL_DATE_FORMAT;
            }
        }
        foreach ($this->types->values() as $index => $type) {
            $columns[Coordinate::stringFromColumnIndex(count(StaffListReportService::COLUMNS) + ($index * 2) + 2)] = '#,##0.00';
        }

        return $columns;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $lastColumn = $sheet->getHighestColumn();
            $sheet->freezePane('E2');
            $sheet->setAutoFilter('A1:'.$lastColumn.$sheet->getHighestRow());
            $sheet->getDefaultColumnDimension()->setWidth(22);
            $sheet->getColumnDimension('D')->setWidth(34);
            $sheet->getColumnDimension('L')->setWidth(38);
            $sheet->getColumnDimension($lastColumn)->setWidth(55);
            $sheet->getStyle($lastColumn.'2:'.$lastColumn.max(2, $sheet->getHighestRow()))->getAlignment()->setWrapText(true);
            $sheet->getRowDimension(1)->setRowHeight(44);
            $sheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '134E4A']],
                'alignment' => ['wrapText' => true, 'vertical' => 'center'],
            ]);
            $sheet->getStyle('A2:'.$lastColumn.max(2, $sheet->getHighestRow()))->getAlignment()->setVertical('top');
        }];
    }
}
