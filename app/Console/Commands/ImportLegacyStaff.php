<?php

namespace App\Console\Commands;

use App\Domain\Legacy\Services\LegacyStaffImportService;
use Illuminate\Console\Command;

class ImportLegacyStaff extends Command
{
    protected $signature = 'legacy:import-staff
        {--dry-run : Read and normalize legacy staff rows without writing anything}
        {--limit=100 : Maximum number of legacy rows to process}
        {--mda= : Restrict import to a single legacy MDA name/code}
        {--include-retired : Include retired staff as well as active staff}
        {--only-retired : Import only retired staff}
        {--source=staff_list : Legacy source table to read from}
        {--publish : Publish staged rows into canonical staff tables}';

    protected $description = 'Stage and optionally publish legacy staff data into the new system without modifying the legacy database.';

    public function handle(LegacyStaffImportService $service): int
    {
        $this->components->info('Starting legacy staff import. The legacy connection will be used in read-only fashion.');

        $summary = $service->import([
            'dry_run' => (bool) $this->option('dry-run'),
            'limit' => (int) $this->option('limit'),
            'mda' => $this->option('mda'),
            'include_retired' => (bool) $this->option('include-retired'),
            'only_retired' => (bool) $this->option('only-retired'),
            'source' => (string) $this->option('source'),
            'publish' => (bool) $this->option('publish'),
        ]);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows Read', $summary['rows_read']],
                ['Rows Staged', $summary['rows_staged']],
                ['Rows Published', $summary['rows_published']],
                ['Published Created', $summary['published_created']],
                ['Published Updated', $summary['published_updated']],
                ['Rows Ready To Publish', $summary['rows_ready_to_publish']],
                ['Invalid Rows', $summary['invalid_rows']],
                ['Active Staff', $summary['active_staff']],
                ['Retired Staff', $summary['retired_staff']],
                ['Duplicate-Risk Rows', $summary['duplicate_risk_rows']],
                ['Matched Existing Staff', $summary['matched_existing_staff_count']],
                ['Rows With Warnings', $summary['rows_with_warnings']],
                ['Rows With Errors', $summary['rows_with_errors']],
                ['Missing MDA', $summary['missing_mda']],
                ['Missing Department', $summary['missing_department']],
                ['Missing Station', $summary['missing_station']],
                ['Missing Cadre', $summary['missing_cadre']],
                ['Missing Rank', $summary['missing_rank']],
                ['Missing Salary Scale', $summary['missing_salary_scale']],
                ['Missing Qualification', $summary['missing_qualification']],
                ['Missing Level', $summary['missing_level']],
                ['Missing Step', $summary['missing_step']],
                ['EDOR Mismatches', $summary['edor_mismatch_count']],
                ['Next Promotion Mismatches', $summary['next_promotion_mismatch_count']],
                ['Call Allowances Resolved', $summary['call_allowance_resolved']],
                ['Call Allowances Unresolved', $summary['call_allowance_unresolved']],
            ],
        );

        if ($summary['row_status_counts'] !== []) {
            $this->newLine();
            $this->table(
                ['Row Status', 'Count'],
                collect($summary['row_status_counts'])
                    ->map(fn (int $count, string $status): array => [$status, $count])
                    ->values()
                    ->all(),
            );
        }

        if ($summary['warning_counts'] !== []) {
            $this->newLine();
            $this->table(
                ['Warning Code', 'Count'],
                collect($summary['warning_counts'])
                    ->sortDesc()
                    ->map(fn (int $count, string $code): array => [$code, $count])
                    ->values()
                    ->all(),
            );
        }

        if ($summary['error_counts'] !== []) {
            $this->newLine();
            $this->table(
                ['Error Code', 'Count'],
                collect($summary['error_counts'])
                    ->sortDesc()
                    ->map(fn (int $count, string $code): array => [$code, $count])
                    ->values()
                    ->all(),
            );
        }

        if ($summary['warnings'] !== []) {
            $this->newLine();
            $this->components->warn('Warnings encountered during staff import:');

            foreach (array_slice(array_unique($summary['warnings']), 0, 25) as $warning) {
                $this->line('- '.$warning);
            }
        }

        if ($summary['errors'] !== []) {
            $this->newLine();
            $this->components->error('Errors encountered during staff import:');

            foreach ($summary['errors'] as $error) {
                $this->line('- '.$error);
            }
        }

        if ($summary['dry_run']) {
            $this->components->info('Dry run completed. No staff or staging records were committed.');
        } elseif ($summary['publish']) {
            $this->components->info('Legacy staff staging and publication completed successfully.');
        } else {
            $this->components->info('Legacy staff staging completed successfully.');
        }

        return self::SUCCESS;
    }
}
