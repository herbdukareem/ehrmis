<?php

namespace App\Jobs;

use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementSheetGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateMovementWorkbook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public int $workbookId,
        public int $year,
        public int $budgetYear,
        public int $budgetMinimumStep,
    ) {
    }

    public function handle(MovementSheetGenerationService $service): void
    {
        $service->populateWorkbook(
            MovementWorkbook::query()->findOrFail($this->workbookId),
            $this->year,
            $this->budgetYear,
            $this->budgetMinimumStep,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $workbook = MovementWorkbook::query()->find($this->workbookId);

        if (! $workbook) {
            return;
        }

        $summary = $workbook->summary ?? [];
        $summary['generation_failure'] = $exception?->getMessage() ?? 'The background generation job failed.';

        $workbook->forceFill([
            'status' => 'generation_failed',
            'summary' => $summary,
        ])->save();
    }
}
