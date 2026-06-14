<?php

namespace App\Console\Commands;

use App\Domain\Legacy\Services\LegacyStaffImportReviewService;
use Illuminate\Console\Command;

class ReviewLegacyStaffImport extends Command
{
    protected $signature = 'legacy:review-staff-import
        {--batch-id= : Review a specific import batch id}
        {--issue-code= : Limit sample issues to a specific issue code}
        {--limit=10 : Number of sample issues to display}';

    protected $description = 'Review a legacy staff import batch with QA-focused summary counts and issue samples.';

    public function handle(LegacyStaffImportReviewService $service): int
    {
        $review = $service->review(
            $this->option('batch-id') ? (int) $this->option('batch-id') : null,
            (int) $this->option('limit'),
            $this->option('issue-code') ? (string) $this->option('issue-code') : null,
        );

        $batch = $review['batch'];
        $summary = $review['summary'];

        $this->components->info('Reviewing legacy staff import batch #'.$batch->id.'.');

        $this->table(
            ['Field', 'Value'],
            [
                ['Batch Id', $batch->id],
                ['Status', $batch->status],
                ['Source Table', $batch->source_table],
                ['Started At', optional($batch->started_at)?->toDateTimeString()],
                ['Completed At', optional($batch->completed_at)?->toDateTimeString()],
                ['Rows Read', $summary['rows_read'] ?? 0],
                ['Rows Staged', $summary['rows_staged'] ?? 0],
                ['Rows Published', $summary['rows_published'] ?? 0],
                ['Rows Ready To Publish', $summary['rows_ready_to_publish'] ?? 0],
                ['Invalid Rows', $summary['invalid_rows'] ?? 0],
                ['Retired Staff', $summary['retired_staff'] ?? 0],
                ['Duplicate-Risk Rows', $summary['duplicate_risk_rows'] ?? 0],
                ['Matched Existing Staff', $summary['matched_existing_staff_count'] ?? 0],
                ['Call Allowances Resolved', $summary['call_allowance_resolved'] ?? 0],
                ['Call Allowances Unresolved', $summary['call_allowance_unresolved'] ?? 0],
            ],
        );

        if ($review['row_status_counts'] !== []) {
            $this->newLine();
            $this->table(
                ['Row Status', 'Count'],
                collect($review['row_status_counts'])
                    ->map(fn (int $count, string $status): array => [$status, $count])
                    ->values()
                    ->all(),
            );
        }

        if ($review['severity_counts'] !== []) {
            $this->newLine();
            $this->table(
                ['Severity', 'Count'],
                collect($review['severity_counts'])
                    ->map(fn (int $count, string $severity): array => [$severity, $count])
                    ->values()
                    ->all(),
            );
        }

        if ($review['issue_code_counts'] !== []) {
            $this->newLine();
            $this->table(
                ['Issue Code', 'Count'],
                collect($review['issue_code_counts'])
                    ->map(fn (int $count, string $code): array => [$code, $count])
                    ->values()
                    ->all(),
            );
        }

        if ($review['sample_issues'] !== []) {
            $this->newLine();
            $this->table(
                ['Row Id', 'Dedupe Key', 'Severity', 'Issue Code', 'Message'],
                $review['sample_issues'],
            );
        }

        return self::SUCCESS;
    }
}
