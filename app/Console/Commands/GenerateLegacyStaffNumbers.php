<?php

namespace App\Console\Commands;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Services\LegacyStaffImportQueryService;
use App\Domain\Legacy\Services\LegacyStaffNumberGenerationService;
use App\Enums\RecordStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class GenerateLegacyStaffNumbers extends Command
{
    protected $signature = 'legacy:generate-staff-numbers
        {batch_id : The staged import batch to update}
        {--user= : Authorized reviewer user ID, recorded in the audit trail}
        {--dry-run : Count eligible records without changing them}';

    protected $description = 'Generate unique system staff numbers for missing or provisional identifiers in a staged batch.';

    public function handle(LegacyStaffNumberGenerationService $service, LegacyStaffImportQueryService $queryService): int
    {
        if (! ctype_digit((string) $this->option('user'))) {
            $this->components->error('Provide --user with an authorized reviewer user ID.');

            return self::FAILURE;
        }

        $user = User::query()->findOrFail((int) $this->option('user'));
        if ($user->status !== RecordStatus::ACTIVE) {
            $this->components->error('The reviewer account must be active.');

            return self::FAILURE;
        }

        $previousUser = Auth::user();
        Auth::setUser($user);

        try {
            $batch = LegacyStaffImportBatch::query()->findOrFail((int) $this->argument('batch_id'));
            $dryRun = (bool) $this->option('dry-run');
            $result = $service->generateBatch($batch, $user, $dryRun);
            $summary = $queryService->summarizeBatch($batch, $user);

            $this->table(['Metric', 'Count'], [
                ['Eligible records', $result['eligible']],
                ['Staff numbers generated', $result['generated']],
                ['Skipped: missing MDA', $result['missing_mda']],
                ['Skipped: matched existing staff', $result['matched_staff']],
                ['Remaining warnings', $summary['warnings_count']],
                ['Remaining errors', $summary['errors_count']],
            ]);
            $this->components->info($dryRun ? 'Preview complete. No records were changed.' : 'Staff number generation completed.');

            return self::SUCCESS;
        } finally {
            $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
        }
    }
}
