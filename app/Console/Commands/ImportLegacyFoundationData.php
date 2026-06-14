<?php

namespace App\Console\Commands;

use App\Domain\Legacy\Services\LegacyFoundationImportService;
use Illuminate\Console\Command;

class ImportLegacyFoundationData extends Command
{
    protected $signature = 'legacy:import-foundation
        {--include-users : Import active legacy users into the new system}
        {--dry-run : Read legacy data and simulate writes without committing them}
        {--default-password=password : Temporary password assigned to imported users}
        {--default-state=Niger : Default state value for imported locations}';

    protected $description = 'Import foundation data from the legacy ministry_of_health database into the new system without modifying the legacy database.';

    public function handle(LegacyFoundationImportService $service): int
    {
        $this->components->info('Starting legacy foundation import. The legacy connection will be used in read-only fashion.');

        $summary = $service->import([
            'include_users' => (bool) $this->option('include-users'),
            'dry_run' => (bool) $this->option('dry-run'),
            'default_password' => (string) $this->option('default-password'),
            'default_state' => (string) $this->option('default-state'),
        ]);

        $this->table(
            ['Entity', 'Created', 'Updated', 'Skipped'],
            [
                ['MDAs', $summary['mdas']['created'], $summary['mdas']['updated'], $summary['mdas']['skipped']],
                ['Departments', $summary['departments']['created'], $summary['departments']['updated'], $summary['departments']['skipped']],
                ['Salary Scales', $summary['salary_scales']['created'], $summary['salary_scales']['updated'], $summary['salary_scales']['skipped']],
                ['Cadres', $summary['cadres']['created'], $summary['cadres']['updated'], $summary['cadres']['skipped']],
                ['Ranks', $summary['ranks']['created'], $summary['ranks']['updated'], $summary['ranks']['skipped']],
                ['Qualification Types', $summary['qualification_types']['created'], $summary['qualification_types']['updated'], $summary['qualification_types']['skipped']],
                ['Qualification Ceilings', $summary['qualification_scale_ceilings']['created'], $summary['qualification_scale_ceilings']['updated'], $summary['qualification_scale_ceilings']['skipped']],
                ['Promotion Policies', $summary['promotion_policies']['created'], $summary['promotion_policies']['updated'], $summary['promotion_policies']['skipped']],
                ['Locations', $summary['locations']['created'], $summary['locations']['updated'], $summary['locations']['skipped']],
                ['Stations', $summary['stations']['created'], $summary['stations']['updated'], $summary['stations']['skipped']],
                ['Users', $summary['users']['created'], $summary['users']['updated'], $summary['users']['skipped']],
            ],
        );

        if ($summary['warnings'] !== []) {
            $this->newLine();
            $this->components->warn('Warnings encountered during import:');

            foreach ($summary['warnings'] as $warning) {
                $this->line('- '.$warning);
            }
        }

        if ($summary['errors'] !== []) {
            $this->newLine();
            $this->components->error('Errors encountered during import:');

            foreach ($summary['errors'] as $error) {
                $this->line('- '.$error);
            }
        }

        if ($summary['dry_run']) {
            $this->components->info('Dry run completed. No new-system records were committed.');
        } else {
            $this->components->info('Legacy foundation import completed successfully.');

            if ((bool) $this->option('include-users')) {
                $this->line('Imported users were assigned the temporary password: '.$this->option('default-password'));
            }
        }

        return self::SUCCESS;
    }
}
