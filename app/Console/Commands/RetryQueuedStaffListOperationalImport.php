<?php

namespace App\Console\Commands;

use App\Domain\Imports\OperationalDataImportService;
use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use Illuminate\Console\Command;

class RetryQueuedStaffListOperationalImport extends Command
{
    protected $signature = 'operational-imports:retry-staff-list
        {batch_id : The legacy staff import batch ID to resume}';

    protected $description = 'Resume a queued or failed operational staff-list import batch using its stored upload path.';

    public function handle(OperationalDataImportService $service): int
    {
        $batch = LegacyStaffImportBatch::query()->findOrFail((int) $this->argument('batch_id'));
        $summary = $batch->summary ?? [];
        $storedPath = $summary['queued_file_path'] ?? null;

        if (! is_string($storedPath) || $storedPath === '') {
            $this->components->error('This batch does not have a recoverable queued file path.');

            return self::FAILURE;
        }

        if (! $batch->created_by) {
            $this->components->error('This batch has no creator associated with it, so it cannot be resumed safely.');

            return self::FAILURE;
        }

        $service->processQueuedStaffListImport($batch->id, $storedPath, (int) $batch->created_by);
        $this->components->info('Staff-list import batch resumed.');

        return self::SUCCESS;
    }
}
