<?php

namespace App\Jobs;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Services\LegacyStaffImportPublicationService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class PublishLegacyStaffImportBatch implements ShouldQueue
{
    use Queueable;

    private const SLICE_RUNTIME_SECONDS = 35;

    private const SLICE_ROW_LIMIT = 10;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $batchId,
        public int $userId,
    ) {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('legacy-staff-import-publish:'.$this->batchId))
                ->releaseAfter(10)
                ->expireAfter(600),
        ];
    }

    public function handle(LegacyStaffImportPublicationService $service): void
    {
        $result = $service->publishBatchSlice(
            LegacyStaffImportBatch::query()->findOrFail($this->batchId),
            User::query()->findOrFail($this->userId),
            self::SLICE_RUNTIME_SECONDS,
            self::SLICE_ROW_LIMIT,
        );

        if (! $result['complete']) {
            self::dispatch($this->batchId, $this->userId)->delay(now()->addSecond());
        }
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
