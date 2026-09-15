<?php

namespace App\Domain\Movement\Exports;

use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementDepartmentSummaryService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MovementSummaryExport implements WithMultipleSheets
{
    public function __construct(
        protected MovementWorkbook $workbook,
        protected MovementDepartmentSummaryService $summaryService,
        protected ?int $departmentId = null,
    ) {}

    public function sheets(): array
    {
        $this->workbook->loadMissing('mda');
        $departments = $this->summaryService->summarize($this->workbook)
            ->filter(fn (array $department): bool => $this->departmentId === null || $department['department_id'] === $this->departmentId);
        if ($departments->isEmpty()) {
            $departments = collect([['department' => 'No records', 'rows' => []]]);
        }
        $usedTitles = [];

        return $departments->map(function (array $department) use (&$usedTitles): MovementSummarySheet {
            return new MovementSummarySheet($this->workbook, $department, $this->sheetTitle($department['department'], $usedTitles));
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
