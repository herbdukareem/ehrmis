<?php

namespace App\Console\Commands;

use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementWorkbookWorkflowService;
use Illuminate\Console\Command;

class UpdateMovementWorkbookStatus extends Command
{
    protected $signature = 'movement:update-workbook-status
        {workbook : Movement workbook id}
        {action : One of reviewed, approved, locked, reopened}';

    protected $description = 'Update the workflow status of a movement workbook.';

    public function handle(MovementWorkbookWorkflowService $service): int
    {
        $workbook = MovementWorkbook::query()->findOrFail((int) $this->argument('workbook'));
        $action = (string) $this->argument('action');

        $workbook = match ($action) {
            'reviewed' => $service->markReviewed($workbook),
            'approved' => $service->approve($workbook),
            'locked' => $service->lock($workbook),
            'reopened' => $service->reopen($workbook),
            default => throw new \InvalidArgumentException('Unsupported movement workbook action.'),
        };

        $this->components->info('Movement workbook status updated successfully.');
        $this->table(
            ['Field', 'Value'],
            [
                ['Workbook Id', $workbook->id],
                ['Status', $workbook->status],
                ['Reviewed At', optional($workbook->reviewed_at)?->toDateTimeString()],
                ['Approved At', optional($workbook->approved_at)?->toDateTimeString()],
                ['Locked At', optional($workbook->locked_at)?->toDateTimeString()],
            ],
        );

        return self::SUCCESS;
    }
}
