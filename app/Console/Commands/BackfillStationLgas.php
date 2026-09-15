<?php

namespace App\Console\Commands;

use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Organization\Support\StationLgaCatalog;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillStationLgas extends Command
{
    protected $signature = 'stations:backfill-lga {mda : MDA code or ID} {--dry-run : Preview matching stations without saving}';

    protected $description = 'Fill missing station LGAs from the supplied legacy station list for one MDA.';

    public function handle(AuditLogService $audit): int
    {
        $identifier = (string) $this->argument('mda');
        $mda = Mda::query()->where(is_numeric($identifier) ? 'id' : 'code', is_numeric($identifier) ? $identifier : strtoupper($identifier))->first();
        if (! $mda) {
            $this->components->error('MDA not found.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = ['updated' => 0, 'already_set' => 0, 'unmatched' => 0];
        $rows = DB::transaction(function () use ($mda, $dryRun, $audit, &$counts): array {
            $rows = [];
            foreach (Station::query()->where('mda_id', $mda->id)->orderBy('name')->lockForUpdate()->get() as $station) {
                $lga = StationLgaCatalog::lgaFor($station->name);
                if (trim((string) $station->lga) !== '') {
                    $counts['already_set']++;
                    $rows[] = [$station->id, $station->name, $station->lga, 'Already set'];

                    continue;
                }
                if ($lga === null) {
                    $counts['unmatched']++;
                    $rows[] = [$station->id, $station->name, '-', 'Needs manual LGA'];

                    continue;
                }
                if (! $dryRun) {
                    $before = $station->toArray();
                    $station->update(['lga' => $lga]);
                    $audit->logUpdated($station, $before, ['source' => 'station_lga_list_2026_09_11', 'mda_id' => $mda->id]);
                }
                $counts['updated']++;
                $rows[] = [$station->id, $station->name, $lga, $dryRun ? 'Would update' : 'Updated'];
            }

            return $rows;
        });

        $this->table(['ID', 'Station', 'LGA', 'Result'], $rows);
        $this->components->info($mda->code.': '.$counts['updated'].($dryRun ? ' would update' : ' updated').', '.$counts['already_set'].' already set, '.$counts['unmatched'].' need manual LGA.');

        return self::SUCCESS;
    }
}
