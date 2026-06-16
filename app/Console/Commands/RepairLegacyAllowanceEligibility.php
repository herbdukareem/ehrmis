<?php

namespace App\Console\Commands;

use App\Domain\Legacy\Services\LegacyAllowanceEligibilityRepairService;
use Illuminate\Console\Command;

class RepairLegacyAllowanceEligibility extends Command
{
    protected $signature = 'legacy:repair-allowance-eligibility';

    protected $description = 'Recalculate allowance eligibility for published legacy staff from preserved raw import rows.';

    public function handle(LegacyAllowanceEligibilityRepairService $service): int
    {
        $summary = $service->repair();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows Processed', $summary['rows_processed']],
                ['Rows Changed', $summary['rows_changed']],
                ['Assignments Updated', $summary['assignments_updated']],
                ['Assignments Created', $summary['assignments_created']],
            ],
        );

        return self::SUCCESS;
    }
}
