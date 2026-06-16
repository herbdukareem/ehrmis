<?php

namespace App\Console\Commands;

use App\Domain\Legacy\Services\LegacyMovementDataRepairService;
use Illuminate\Console\Command;

class RepairLegacyMovementData extends Command
{
    protected $signature = 'legacy:repair-movement-data
        {--regenerate-workbooks : Regenerate movement lines after repairing promotion data}
        {--reopen-approved : Reopen reviewed, approved, or locked workbooks before regeneration}';

    protected $description = 'Import promotion policies, repair published staff promotion dates, and optionally regenerate movement workbooks.';

    public function handle(LegacyMovementDataRepairService $service): int
    {
        $summary = $service->repair(
            (bool) $this->option('regenerate-workbooks'),
            (bool) $this->option('reopen-approved'),
        );

        $this->table(['Metric', 'Value'], collect($summary)->map(fn ($value, $key) => [$key, $value])->values()->all());

        return self::SUCCESS;
    }
}
