<?php

namespace App\Domain\Budget\Exports;

use App\Domain\Budget\Models\BudgetWorkbook;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class BudgetDepartmentReportExport implements WithMultipleSheets
{
    public function __construct(protected BudgetWorkbook $workbook, protected array $report) {}

    public function sheets(): array
    {
        $groups = collect($this->report['groups']);
        if ($this->report['type'] === 'qualification-distribution') {
            $groups = $groups->groupBy(fn (array $group) => $group['department_id'] ?? 'unassigned')
                ->map(fn ($sections): array => ['department' => $sections->first()['department'], 'sections' => $sections->values()]);
        }
        if ($groups->isEmpty()) {
            $groups = collect([['department' => 'No records', 'rows' => collect(), 'sections' => collect()]]);
        }

        $usedTitles = [];

        return $groups->map(function (array $group) use (&$usedTitles) {
            $title = $this->sheetTitle($group['department'], $usedTitles);

            return $this->report['type'] === 'staff-list'
                ? new BudgetStaffListSheet($this->workbook, $this->report['title'], $group, $title)
                : new BudgetQualificationSheet($this->workbook, $this->report['title'], $group, $title);
        })->values()->all();
    }

    protected function sheetTitle(string $department, array &$usedTitles): string
    {
        $name = str_replace(['\\', '/', '?', '*', ':', '[', ']'], '-', $department);
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name), " '\t\n\r\0\x0B") ?: 'Unassigned';
        if (strcasecmp($name, 'History') === 0) {
            $name .= ' department';
        }
        $number = 1;
        do {
            $suffix = $number > 1 ? ' ('.$number.')' : '';
            $title = rtrim(mb_substr($name, 0, 31 - mb_strlen($suffix)), " '").$suffix;
            $number++;
        } while (in_array(mb_strtolower($title), $usedTitles, true));
        $usedTitles[] = mb_strtolower($title);

        return $title;
    }
}
