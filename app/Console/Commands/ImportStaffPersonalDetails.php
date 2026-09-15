<?php

namespace App\Console\Commands;

use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Services\StaffPersonalDetailsImportService;
use App\Domain\Staff\Services\StaffPersonalDetailsWorkbookReader;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportStaffPersonalDetails extends Command
{
    protected $signature = 'staff:import-personal-details {file : Source Excel workbook}
        {--mda=HMB : Target MDA code}
        {--sheet=First Sheet - Updated : Worksheet containing the staff list}
        {--actor= : Platform administrator user ID recorded in the audit log}
        {--dry-run : Preview changes without updating staff}';

    protected $description = 'Update existing staff file numbers and LGAs of origin from a workbook; assign unused G001-G999 numbers where missing.';

    public function handle(StaffPersonalDetailsWorkbookReader $reader, StaffPersonalDetailsImportService $service): int
    {
        $actor = User::query()->find($this->option('actor'));
        if (! $actor || ! $actor->hasGlobalMdaAccess() || ! $actor->can('update-staff')) {
            $this->components->error('Provide --actor with a platform administrator who can update staff.');

            return self::FAILURE;
        }
        Auth::setUser($actor);
        $mda = Mda::query()->where('code', strtoupper((string) $this->option('mda')))->firstOrFail();
        $file = (string) $this->argument('file');
        $sheet = (string) $this->option('sheet');
        $rows = $reader->read($file, $sheet);
        $report = $service->import($mda, $actor, $rows, [
            'filename' => basename($file), 'sheet' => $sheet, 'sha256' => hash_file('sha256', $file),
        ], (bool) $this->option('dry-run'));
        $reportPath = 'staff-personal-details/'.$mda->code.'-'.Str::uuid().($report['dry_run'] ? '-preview.json' : '-applied.json');
        Storage::disk('local')->put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->table(['Result', 'Count'], [
            ['Source rows', $report['source_rows']], ['Matched staff', $report['matched_staff']],
            [$report['dry_run'] ? 'Would update' : 'Updated', $report['updated']],
            ['Generated file numbers', $report['generated_file_numbers']], ['Missing LGA in source', $report['missing_lga']],
            ['Unmatched source rows', count($report['unmatched_source_rows'])], ['Conflicts requiring review', count($report['conflicts'])],
            ['Duplicate entries resolved by saved DOB', count($report['resolved_duplicates'])],
            ['Staff outside the workbook', count($report['staff_not_in_source'])],
        ]);
        $this->line('Import report: '.Storage::disk('local')->path($reportPath));

        return self::SUCCESS;
    }
}
