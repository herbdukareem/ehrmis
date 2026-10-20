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

    protected array $columns;

    protected array $groupHeadingRows = [];

    protected array $columnHeadingRows = [];

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
        $this->columns = $report->reportColumns($filters, $this->types);
    }

    public function title(): string
    {
        return StaffListReportService::REPORT_TYPES[$this->filters['report_type'] ?? 'staff_list'];
    }

    public function headings(): array
    {
        return $this->isGrouped() ? [] : array_values($this->columns);
    }

    protected function isGrouped(): bool
    {
        return ($this->filters['report_type'] ?? 'staff_list') !== 'staff_list';
    }

    public function generator(): Generator
    {
        $groups = $this->isGrouped() ? $this->report->groupedRows($this->user, $this->filters)->keyBy('id') : collect();
        $previousGroup = null;
        $rowNumber = 0;
        // Export all matching staff, irrespective of the preview page.
        foreach ($this->report->query($this->user, $this->filters)->lazy(250) as $staff) {
            if ($this->isGrouped()) {
                $key = $this->report->groupKey($staff, $this->filters['report_type']);
                if ($key !== $previousGroup) {
                    $group = $groups->get($key);
                    $this->groupHeadingRows[] = ++$rowNumber;
                    yield [$group['group_label'].' ('.$group['staff_count'].' staff)'];
                    $this->columnHeadingRows[] = ++$rowNumber;
                    yield array_values($this->columns);
                    $previousGroup = $key;
                }
            }
            $record = $this->report->row($staff, $this->types);
            foreach (StaffListReportService::DATE_COLUMNS as $key) {
                $record[$key] = $record[$key] ? Date::PHPToExcel(new DateTimeImmutable($record[$key])) : null;
            }
            ++$rowNumber;
            yield array_map(fn ($key) => $record[$key], array_keys($this->columns));
        }
        if ($this->isGrouped() && !$rowNumber) {
            $this->columnHeadingRows[] = 1;
            yield array_values($this->columns);
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
        $columns = [];
        foreach (array_keys($this->columns) as $index => $key) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            if (in_array($key, StaffListReportService::DATE_COLUMNS, true)) {
                $columns[$column] = ReportFormatter::EXCEL_DATE_FORMAT;
            } elseif (in_array($key, ['basic_salary', 'allowance_total', 'gross_salary'], true) || preg_match('/^allowance_\d+$/', $key)) {
                $columns[$column] = '#,##0.00';
            } elseif (in_array($key, ['staff_count', 'level', 'step'], true)) {
                $columns[$column] = '0';
            }
        }

        return $columns;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            $lastColumn = $sheet->getHighestColumn();
            $sheet->freezePane($this->isGrouped() ? 'A3' : (!isset($this->filters['columns']) ? 'E2' : 'A2'));
            if (!$this->isGrouped()) {
                $sheet->setAutoFilter('A1:'.$lastColumn.$sheet->getHighestRow());
            }
            $sheet->getDefaultColumnDimension()->setWidth(22);
            foreach (array_keys($this->columns) as $index => $key) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                if (in_array($key, ['full_name', 'mda', 'group_label', 'calculation_note'], true)) {
                    $sheet->getColumnDimension($column)->setWidth($key === 'calculation_note' ? 55 : 38);
                    $sheet->getStyle($column.'2:'.$column.max(2, $sheet->getHighestRow()))->getAlignment()->setWrapText(true);
                }
            }
            foreach ($this->isGrouped() ? $this->columnHeadingRows : [1] as $row) {
                $sheet->getRowDimension($row)->setRowHeight(44);
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '134E4A']],
                    'alignment' => ['wrapText' => true, 'vertical' => 'center'],
                ]);
            }
            foreach ($this->groupHeadingRows as $row) {
                if ($lastColumn !== 'A') {
                    $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
                }
                $sheet->getRowDimension($row)->setRowHeight(30);
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E6F2EF']],
                    'alignment' => ['wrapText' => true, 'vertical' => 'center'],
                ]);
            }
            $sheet->getStyle('A2:'.$lastColumn.max(2, $sheet->getHighestRow()))->getAlignment()->setVertical('top');
        }];
    }
}
