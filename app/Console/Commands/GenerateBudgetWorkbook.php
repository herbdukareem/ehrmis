<?php

namespace App\Console\Commands;

use App\Domain\Budget\Services\BudgetGenerationService;
use App\Domain\Movement\Models\MovementWorkbook;
use Illuminate\Console\Command;

class GenerateBudgetWorkbook extends Command
{
    protected $signature = 'budget:generate-workbook
        {movementWorkbook : Approved or locked movement workbook id}';

    protected $description = 'Generate a recurrent budget workbook from an approved movement workbook.';

    public function handle(BudgetGenerationService $service): int
    {
        $movementWorkbook = MovementWorkbook::query()
            ->with(['summaries', 'mda'])
            ->findOrFail((int) $this->argument('movementWorkbook'));

        $budgetWorkbook = $service->generateFromMovementWorkbook($movementWorkbook, auth()->id());
        $summary = $budgetWorkbook->summary ?? [];

        $this->components->info('Budget workbook generated successfully.');
        $this->table(
            ['Field', 'Value'],
            [
                ['Budget Workbook Id', $budgetWorkbook->id],
                ['MDA', $budgetWorkbook->mda?->code.' - '.$budgetWorkbook->mda?->name],
                ['Year', $budgetWorkbook->year],
                ['Status', $budgetWorkbook->status],
                ['Line Count', $summary['line_count'] ?? 0],
                ['Staff Count', $summary['staff_count'] ?? 0],
                ['Current Gross Total', $summary['current_gross_total'] ?? 0],
                ['Proposed Gross Total', $summary['proposed_gross_total'] ?? 0],
                ['Variance Total', $summary['variance_total'] ?? 0],
            ],
        );

        return self::SUCCESS;
    }
}
