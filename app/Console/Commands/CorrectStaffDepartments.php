<?php

namespace App\Console\Commands;

use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Services\StaffDepartmentCorrectionService;
use App\Domain\Staff\Services\StaffPersonalDetailsWorkbookReader;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CorrectStaffDepartments extends Command
{
    protected $signature = 'staff:correct-departments {file : Source staff workbook}
        {--mda=HMB : MDA code} {--sheet=First Sheet - Updated : Staff worksheet}
        {--actor= : Platform administrator user ID for the audit trail}
        {--dry-run : Preview department corrections without changing staff}';

    protected $description = 'Correct staff departments from a verified workbook, preserving generated workbook snapshots.';

    public function handle(StaffPersonalDetailsWorkbookReader $reader, StaffDepartmentCorrectionService $service): int
    {
        $actor = User::query()->find($this->option('actor'));
        if (! $actor || ! $actor->hasGlobalMdaAccess() || ! $actor->can('update-staff-appointment')) {
            $this->components->error('Provide --actor with a platform administrator who can update staff appointments.');

            return self::FAILURE;
        }
        Auth::setUser($actor);
        $mda = Mda::query()->visibleToUser($actor)->where('code', strtoupper((string) $this->option('mda')))->firstOrFail();
        $file = (string) $this->argument('file');
        $sheet = (string) $this->option('sheet');
        $rows = $reader->read($file, $sheet);
        $report = $service->correct($mda, $actor, $rows, [
            'filename' => basename($file), 'sheet' => $sheet, 'sha256' => hash_file('sha256', $file),
        ], (bool) $this->option('dry-run'));
        $path = 'staff-departments/'.$mda->code.'-'.Str::uuid().($report['dry_run'] ? '-preview.json' : '-applied.json');
        Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->table(['Result', 'Count'], [
            ['Source rows', $report['source_rows']], ['Matched staff', $report['matched_staff']],
            [$report['dry_run'] ? 'Would correct' : 'Corrected', $report['updated']],
            ['Unmatched rows', count($report['unmatched_rows'])], ['Issues needing review', count($report['issues'])],
            ['Staff outside source', count($report['staff_outside_source'])],
        ]);
        $this->line('Correction report: '.Storage::disk('local')->path($path));
        $this->line('Existing movement and budget snapshots are preserved; rebuild them to reflect corrected departments.');

        return self::SUCCESS;
    }
}
