<?php

namespace App\Console\Commands;

use App\Domain\Movement\Services\MovementSheetGenerationService;
use App\Domain\Organization\Models\Mda;
use Illuminate\Console\Command;

class GenerateMovementSheet extends Command
{
    protected $signature = 'movement:generate-sheet
        {mda : MDA id, code, or exact name}
        {year : Workbook year}';

    protected $description = 'Generate or refresh a draft movement workbook using canonical staff data and SalaryCalculationService.';

    public function handle(MovementSheetGenerationService $service): int
    {
        $mdaInput = (string) $this->argument('mda');
        $year = (int) $this->argument('year');

        $mda = Mda::query()
            ->where(function ($query) use ($mdaInput): void {
                if (is_numeric($mdaInput)) {
                    $query->orWhere('id', (int) $mdaInput);
                }

                $query
                    ->orWhere('code', $mdaInput)
                    ->orWhere('name', $mdaInput);
            })
            ->first();

        if (! $mda) {
            $this->components->error('MDA could not be resolved from the provided argument.');

            return self::FAILURE;
        }

        $workbook = $service->generateForMda($mda->id, $year, auth()->id());
        $summary = $workbook->summary ?? [];

        $this->components->info('Movement workbook generated successfully.');
        $this->table(
            ['Field', 'Value'],
            [
                ['Workbook Id', $workbook->id],
                ['MDA', $mda->code.' - '.$mda->name],
                ['Year', $workbook->year],
                ['Status', $workbook->status],
                ['Staff Considered', $summary['staff_considered'] ?? 0],
                ['Lines Generated', $summary['lines_generated'] ?? 0],
                ['Due For Promotion', $summary['due_for_promotion'] ?? 0],
                ['Retiring In Year', $summary['retiring_in_year'] ?? 0],
                ['Already Retired', $summary['already_retired'] ?? 0],
                ['Blocked', $summary['blocked'] ?? 0],
            ],
        );

        return self::SUCCESS;
    }
}
