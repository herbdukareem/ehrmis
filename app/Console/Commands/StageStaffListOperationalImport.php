<?php

namespace App\Console\Commands;

use App\Domain\Imports\OperationalDataImportService;
use Illuminate\Console\Command;

class StageStaffListOperationalImport extends Command
{
    protected $signature = 'operational-imports:stage-staff-list
        {batch_id : The legacy staff import batch ID}
        {stored_path : The relative storage path of the uploaded spreadsheet}
        {user_id : The user that initiated the import}';

    protected $description = 'Stage an uploaded operational staff-list spreadsheet in a background process.';

    public function handle(OperationalDataImportService $service): int
    {
        $service->processQueuedStaffListImport(
            (int) $this->argument('batch_id'),
            (string) $this->argument('stored_path'),
            (int) $this->argument('user_id'),
        );

        return self::SUCCESS;
    }
}
