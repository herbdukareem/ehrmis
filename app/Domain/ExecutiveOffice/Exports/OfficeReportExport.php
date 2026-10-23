<?php

namespace App\Domain\ExecutiveOffice\Exports;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Services\OfficeReportingService;
use App\Models\User;
use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class OfficeReportExport extends DefaultValueBinder implements FromGenerator, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    public function __construct(protected OfficeReportingService $reports, protected User $actor, protected Office $office, protected array $filters)
    {
        $memory = (string) config('reports.executive_office_export_memory_limit', '512M');
        $current = (string) ini_get('memory_limit');
        if ($current !== '-1' && ini_parse_quantity($memory) > ini_parse_quantity($current)) {
            ini_set('memory_limit', $memory);
        }
        if ((int) ini_get('max_execution_time') > 0) {
            set_time_limit(max((int) ini_get('max_execution_time'), (int) config('reports.executive_office_export_timeout', 120)));
        }
    }

    public function headings(): array
    {
        return ['Reference', 'Type', 'Subject', 'Status', 'Priority', 'Confidentiality', 'Registered', 'Scheduled', 'Deadline', 'Completed'];
    }

    public function generator(): Generator
    {
        foreach ($this->reports->query($this->actor, $this->office, $this->filters)->lazy(250) as $record) {
            $date = fn ($value) => $value?->copy()->timezone($this->office->timezone)->format('d-m-Y H:i');
            yield [$record->reference, $record->type, $record->subject, $record->status, $record->priority, $record->confidentiality, $date($record->created_at), $date($record->starts_at), $date($record->due_at), $date($record->closed_at)];
        }
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
