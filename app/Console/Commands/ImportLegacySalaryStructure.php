<?php

namespace App\Console\Commands;

use App\Domain\Legacy\Services\LegacySalaryStructureImportService;
use Illuminate\Console\Command;

class ImportLegacySalaryStructure extends Command
{
    protected $signature = 'legacy:import-salary-structure
        {--dry-run : Read legacy salary structure data without committing writes}
        {--active-only : Import only active legacy salary rows where status = 1}';

    protected $description = 'Import dynamic salary structure rates and allowance amounts from the legacy staff_salary table without modifying the legacy database.';

    public function handle(LegacySalaryStructureImportService $service): int
    {
        $this->components->info('Starting legacy salary structure import. The legacy connection will be used in read-only fashion.');

        $summary = $service->import([
            'dry_run' => (bool) $this->option('dry-run'),
            'active_only' => true,
        ]);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows Read', $summary['rows_read']],
                ['Salary Rates Created', $summary['salary_rates_created']],
                ['Salary Rates Updated', $summary['salary_rates_updated']],
                ['Allowance Types Created', $summary['allowance_types_created']],
                ['Allowance Types Updated', $summary['allowance_types_updated']],
                ['Salary Allowance Rows Created', $summary['salary_allowance_rows_created']],
                ['Salary Allowance Rows Updated', $summary['salary_allowance_rows_updated']],
                ['Skipped Zero Allowance Amounts', $summary['skipped_zero_allowance_amounts']],
                ['Skipped Missing Salary Scales', $summary['skipped_missing_salary_scales']],
                ['Invalid Rows Skipped', $summary['invalid_rows_skipped']],
                ['Errors Encountered', count($summary['errors'])],
            ],
        );

        if ($summary['warnings'] !== []) {
            $this->newLine();
            $this->components->warn('Warnings encountered during salary structure import:');

            foreach ($summary['warnings'] as $warning) {
                $this->line('- '.$warning);
            }
        }

        if ($summary['errors'] !== []) {
            $this->newLine();
            $this->components->error('Errors encountered during salary structure import:');

            foreach ($summary['errors'] as $error) {
                $this->line('- '.$error);
            }
        }

        if ($summary['dry_run']) {
            $this->components->info('Dry run completed. No salary structure records were committed.');
        } else {
            $this->components->info('Legacy salary structure import completed successfully.');
        }

        return self::SUCCESS;
    }
}
