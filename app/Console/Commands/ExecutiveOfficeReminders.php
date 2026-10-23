<?php

namespace App\Console\Commands;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Services\OfficeNotificationService;
use App\Domain\Module\Services\ModuleAccessService;
use Illuminate\Console\Command;

class ExecutiveOfficeReminders extends Command
{
    protected $signature = 'executive-office:reminders {--mda= : Process one MDA}';

    protected $description = 'Create idempotent in-app executive-office reminders for enabled MDAs';

    public function handle(OfficeNotificationService $notifications, ModuleAccessService $modules): int
    {
        $count = 0;
        Office::query()->when($this->option('mda'), fn ($q, $id) => $q->where('mda_id', (int) $id))->chunkById(50, function ($offices) use ($notifications, $modules, &$count) {
            foreach ($offices as $office) {
                if ($modules->mdaHasModule($office->mda_id, 'executive_office')) {
                    $count += $notifications->reminders($office);
                }
            }
        });
        $this->info('Checked '.$count.' office reminders.');

        return self::SUCCESS;
    }
}
