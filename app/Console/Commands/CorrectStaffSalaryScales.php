<?php

namespace App\Console\Commands;

use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Services\StaffPersonalDetailsWorkbookReader;
use App\Domain\Staff\Services\StaffSalaryScaleCorrectionService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CorrectStaffSalaryScales extends Command
{
    protected $signature = 'staff:correct-salary-scales {file : Authoritative source staff workbook}
        {--mda=HMB : MDA code} {--sheet=First Sheet - Updated : Worksheet}
        {--actor= : Platform administrator user ID for audit attribution}
        {--apply : Apply unambiguous corrections; default is a read-only preview}';

    protected $description = 'Verify salary placements against explicit workbook grades, recording discrepancies and preserving approved workbooks.';

    public function handle(StaffPersonalDetailsWorkbookReader $reader, StaffSalaryScaleCorrectionService $service): int
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
        $hash = hash_file('sha256', $file);
        $rows = $reader->read($file, $sheet, true);
        gc_collect_cycles();
        if ($hash !== hash_file('sha256', $file)) {
            throw new RuntimeException('The source workbook changed while being read; retry.');
        }
        $base = 'staff-salary-scales/'.$mda->code.'-'.Str::uuid();
        $write = function (string $path, array $report): void {
            if (! Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Cannot save salary correction evidence.');
            }
        };
        $report = $service->correct($mda, $actor, $rows, [
            'filename' => basename($file), 'sheet' => $sheet, 'sha256' => $hash,
        ], ! $this->option('apply'), fn (array $before) => $write($base.'-before.json', $before));
        $path = $base.($report['dry_run'] ? '-preview.json' : '-applied.json');
        $write($path, $report);
        $this->table(['Result', 'Count'], [
            ['Source rows', $report['source_rows']], ['Matched staff', $report['matched_staff']],
            ['Already correct', $report['verified_count']],
            [$report['dry_run'] ? 'Would correct' : 'Corrected', $report['updated']],
            ['Needs review', count($report['issues'])], ['Unmatched rows', count($report['unmatched_rows'])],
            ['Staff outside source', count($report['staff_outside_source'])],
        ]);
        $this->line('Audit report: '.Storage::disk('local')->path($path));
        $this->line('Movement and budget snapshots retain their saved values until regeneration.');

        return self::SUCCESS;
    }
}
