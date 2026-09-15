<?php

namespace App\Domain\Budget\Exports;

use App\Domain\Budget\Models\BudgetWorkbook;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class RecurrentExpenditureExport implements WithMultipleSheets
{
    public function __construct(protected BudgetWorkbook $workbook, protected array $report) {}

    public function sheets(): array
    {
        $sheets = [];
        $usedTitles = ['grand total'];

        foreach ($this->report['groups'] as $group) {
            $department = $this->cleanTitle($group['department']);
            $scale = mb_substr($this->cleanTitle($group['scale_code']), 0, 10);
            $number = 1;

            do {
                $suffix = ' '.$scale.($number > 1 ? ' ('.$number.')' : '');
                $title = mb_substr($department, 0, 31 - mb_strlen($suffix)).$suffix;
                $number++;
            } while (in_array(mb_strtolower($title), $usedTitles, true));

            $usedTitles[] = mb_strtolower($title);
            $sheets[] = new RecurrentExpenditureSheet($this->workbook, $this->report['title'], $group, $title, $this->report['notes'] ?? []);
        }

        $sheets[] = new RecurrentExpenditureSheet($this->workbook, $this->report['title'], [
            'department' => 'Grand Total',
            'scale' => '',
            'rows' => [],
            'totals' => $this->report['grand_totals'],
        ], 'Grand Total', $this->report['notes'] ?? []);

        return $sheets;
    }

    protected function cleanTitle(string $value): string
    {
        $value = str_replace(['\\', '/', '?', '*', ':', '[', ']'], '-', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        return trim($value, " '\t\n\r\0\x0B") ?: 'Unassigned';
    }
}
