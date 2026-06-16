<?php

namespace App\Jobs;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Services\LegacyStaffImportPublicationService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublishLegacyStaffImportBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public int $batchId,
        public int $userId,
    ) {
    }

    public function handle(LegacyStaffImportPublicationService $service): void
    {
        $service->publishBatch(
            LegacyStaffImportBatch::query()->findOrFail($this->batchId),
            User::query()->findOrFail($this->userId),
        );
    }

    public function failed(?Throwable $exception): void
    {
        $batch = LegacyStaffImportBatch::query()->find($this->batchId);

        if (! $batch) {
            return;
        }

        $summary = $batch->summary ?? [];
        $summary['publication_failure'] = $exception?->getMessage() ?? 'The background publication job failed.';

        $batch->forceFill([
            'status' => 'publication_failed',
            'summary' => $summary,
        ])->save();
    }
}
