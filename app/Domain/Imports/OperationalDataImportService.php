<?php

namespace App\Domain\Imports;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Services\LegacyStaffIdentityMatcher;
use App\Domain\Legacy\Services\LegacyStaffRowNormalizer;
use App\Domain\Legacy\Services\LegacyStaffRowValidator;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Services\QualificationCatalogSyncService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class OperationalDataImportService
{
    public const TYPES = ['departments', 'stations', 'cadres', 'ranks', 'staff-list'];

    public function __construct(
        protected LegacyStaffRowNormalizer $normalizer,
        protected LegacyStaffRowValidator $validator,
        protected LegacyStaffIdentityMatcher $identityMatcher,
        protected QualificationCatalogSyncService $qualificationCatalogSyncService,
    ) {
    }

    public function import(string $type, UploadedFile $file, User $user): array
    {
        $this->configureRuntime();
        $this->qualificationCatalogSyncService->syncQualificationTypes();

        return match ($type) {
            'departments' => $this->importDepartments($this->readPopulatedRows($file), $user),
            'stations' => $this->importStations($this->readPopulatedRows($file), $user),
            'cadres' => $this->importCadres($this->readPopulatedRows($file), $user),
            'ranks' => $this->importRanks($this->readPopulatedRows($file), $user),
            'staff-list' => $this->shouldStageStaffListInBackground()
                ? $this->queueStaffList($file, $user)
                : $this->stageStaffList($this->readPopulatedRows($file), $user),
            default => throw ValidationException::withMessages(['type' => 'Unsupported import type.']),
        };
    }

    protected function configureRuntime(): void
    {
        $maxExecutionSeconds = max(0, (int) config('operational_imports.max_execution_seconds', 900));
        $memoryLimit = trim((string) config('operational_imports.memory_limit', '512M'));

        if (function_exists('set_time_limit')) {
            set_time_limit($maxExecutionSeconds);
        }

        if ($memoryLimit !== '') {
            ini_set('memory_limit', $memoryLimit);
        }
    }

    public function template(string $type, User $user): SpreadsheetTemplateExport
    {
        $defaultMda = $this->defaultAccessibleMda($user);
        $defaultDepartmentCode = $defaultMda?->departments()->value('code') ?? 'CLIN';

        return match ($type) {
            'departments' => new SpreadsheetTemplateExport(
                ['code', 'name', 'mda_code', 'description', 'status'],
                [['CLIN', 'Clinical Services', $defaultMda?->code ?? 'MOH', 'Clinical service department', 'active']],
            ),
            'stations' => new SpreadsheetTemplateExport(
                ['code', 'name', 'mda_code', 'description', 'status'],
                [['HQ', 'Headquarters', $defaultMda?->code ?? 'MOH', 'Main administrative station', 'active']],
            ),
            'cadres' => new SpreadsheetTemplateExport(
                ['name', 'mda_code', 'department_code', 'salary_scale_code', 'description', 'status'],
                [['Medical Officer', $defaultMda?->code ?? 'MOH', $defaultDepartmentCode, 'CM', 'Medical cadre', 'active']],
            ),
            'ranks' => new SpreadsheetTemplateExport(
                ['name', 'cadre_name', 'mda_code', 'salary_scale_code', 'level', 'description', 'status'],
                [['Senior Medical Officer', 'Medical Officer', $defaultMda?->code ?? 'MOH', 'CM', 4, 'Senior clinical rank', 'active']],
            ),
            'staff-list' => new SpreadsheetTemplateExport(
                [
                    'cno', 'psn', 'name', 'sex', 'dob', 'mda', 'department', 'station',
                    'cadre', 'rank', 'salary_scale', 'level', 'step', 'dfa', 'dpa', 'edor',
                    'qualification', 'highest_qualification', 'specialization', 'staff_category',
                    'is_retired', 'shift_', 'hazard_', 'teaching_', 'specialist_', 'rural_', 'call_',
                ],
                [[
                    'C001', 'P001', 'Surname Firstname', 'female', '1985-01-31',
                    $defaultMda?->code ?? 'MOH', 'Clinical Services', 'Headquarters',
                    'Medical Officer', 'Senior Medical Officer', 'CM', 4, 1,
                    '2010-02-01', '2024-01-01', '2045-01-31', 'OND', 'HND',
                    'General Medicine', 'Clinical', 0, 0, 1, 0, 0, 0, 'CALLDOC',
                ]],
            ),
            default => throw ValidationException::withMessages(['type' => 'Unsupported template type.']),
        };
    }

    protected function readRows(UploadedFile|string $file): array
    {
        $import = new SpreadsheetRowsImport();
        Excel::import($import, $file);

        return $import->rows;
    }

    protected function readPopulatedRows(UploadedFile|string $file): array
    {
        $rows = $this->readRows($file);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The spreadsheet contains no data rows.']);
        }

        return $rows;
    }

    protected function importDepartments(array $rows, User $user): array
    {
        return DB::transaction(function () use ($rows, $user): array {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $index => $row) {
                $mda = $this->resolveMda($row, $user, $index);
                $code = Str::upper($this->required($row, 'code', $index));
                $name = $this->required($row, 'name', $index);
                $departmentByCode = Department::withoutGlobalScopes()
                    ->withTrashed()
                    ->where('mda_id', $mda->id)
                    ->whereRaw('LOWER(code) = ?', [strtolower($code)])
                    ->first();
                $departmentByName = Department::withoutGlobalScopes()
                    ->withTrashed()
                    ->where('mda_id', $mda->id)
                    ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->first();

                if ($departmentByCode && $departmentByName && ! $departmentByCode->is($departmentByName)) {
                    $this->rowError($index, 'name', 'The department code and name belong to different existing departments within the selected MDA.');
                }

                $department = $departmentByCode ?? $departmentByName;
                $wasExisting = (bool) $department;

                if ($wasExisting && ! $department->trashed()) {
                    $skipped++;
                    continue;
                }

                $department ??= new Department();
                $department->fill([
                    'mda_id' => $mda->id,
                    'code' => $code,
                    'name' => $name,
                    'description' => $this->nullable($row['description'] ?? null),
                    'status' => $this->status($row['status'] ?? null, $index),
                ]);

                $department->save();
                $department->restore();
                $wasExisting ? $updated++ : $created++;
            }

            return ['type' => 'departments', 'rows_read' => count($rows), 'created' => $created, 'updated' => $updated, 'skipped' => $skipped];
        });
    }

    protected function importStations(array $rows, User $user): array
    {
        return DB::transaction(function () use ($rows, $user): array {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $index => $row) {
                $mda = $this->resolveMda($row, $user, $index);
                $code = Str::upper($this->required($row, 'code', $index));
                $name = $this->required($row, 'name', $index);
                $stationByCode = Station::query()
                    ->forMda($mda->id)
                    ->withTrashed()
                    ->whereRaw('LOWER(code) = ?', [strtolower($code)])
                    ->first();
                $stationByName = Station::query()
                    ->forMda($mda->id)
                    ->withTrashed()
                    ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->first();

                if ($stationByCode && $stationByName && ! $stationByCode->is($stationByName)) {
                    $this->rowError($index, 'name', 'The station code and name belong to different existing stations within the selected MDA.');
                }

                $station = $stationByCode ?? $stationByName;
                $wasExisting = (bool) $station;

                if ($wasExisting && ! $station->trashed()) {
                    $skipped++;
                    continue;
                }

                $station ??= new Station();
                $station->fill([
                    'mda_id' => $mda->id,
                    'code' => $code,
                    'name' => $name,
                    'description' => $this->nullable($row['description'] ?? null),
                    'status' => $this->status($row['status'] ?? null, $index),
                ]);

                $station->save();
                $station->restore();
                $wasExisting ? $updated++ : $created++;
            }

            return ['type' => 'stations', 'rows_read' => count($rows), 'created' => $created, 'updated' => $updated, 'skipped' => $skipped];
        });
    }

    protected function importCadres(array $rows, User $user): array
    {
        return DB::transaction(function () use ($rows, $user): array {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $index => $row) {
                $name = $this->required($row, 'name', $index);
                $department = $this->resolveDepartment($row, $user, $index);
                $scale = $this->resolveSalaryScale($row, $index, (int) $department->mda_id);
                $cadre = Cadre::withTrashed()
                    ->where('department_id', $department->id)
                    ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->where('salary_scale_id', $scale->id)
                    ->first();

                $wasExisting = (bool) $cadre;

                if ($wasExisting && ! $cadre->trashed()) {
                    $skipped++;
                    continue;
                }

                $cadre ??= new Cadre();
                $cadre->fill([
                    'name' => $name,
                    'department_id' => $department->id,
                    'salary_scale_id' => $scale->id,
                    'legacy_department_name' => $department->name,
                    'description' => $this->nullable($row['description'] ?? null),
                    'status' => $this->status($row['status'] ?? null, $index),
                ]);

                $cadre->save();
                $cadre->restore();
                $wasExisting ? $updated++ : $created++;
            }

            return ['type' => 'cadres', 'rows_read' => count($rows), 'created' => $created, 'updated' => $updated, 'skipped' => $skipped];
        });
    }

    protected function importRanks(array $rows, User $user): array
    {
        return DB::transaction(function () use ($rows, $user): array {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $index => $row) {
                $name = $this->required($row, 'name', $index);
                $mda = $this->resolveMda($row, $user, $index);
                $scale = $this->resolveSalaryScale($row, $index, (int) $mda->id);
                $cadreName = $this->required($row, 'cadre_name', $index);
                $cadreQuery = Cadre::query()
                    ->where('salary_scale_id', $scale->id)
                    ->whereRaw('LOWER(name) = ?', [strtolower($cadreName)])
                    ->whereHas('department', fn ($query) => $query->where('mda_id', $mda->id));

                if ($this->nullable($row['department_code'] ?? null) !== null) {
                    $department = $this->resolveDepartment($row, $user, $index);
                    $cadreQuery->where('department_id', $department->id);
                }

                $cadres = $cadreQuery->limit(2)->get();

                if ($cadres->isEmpty()) {
                    $this->rowError($index, 'cadre_name', 'Cadre could not be resolved within the selected MDA and salary scale.');
                }

                if ($cadres->count() > 1) {
                    $this->rowError($index, 'cadre_name', 'Cadre name matches multiple departments. Add department_code to this row to disambiguate.');
                }

                $cadre = $cadres->first();
                $level = $this->parseWholeNumber($row['level'] ?? null);

                if ($level === false || $level < $scale->min_level || $level > $scale->max_level) {
                    $rawLevel = $this->describeValueForError($row['level'] ?? null);
                    $this->rowError(
                        $index,
                        'level',
                        "Received {$rawLevel}. Level must be a whole number between {$scale->min_level} and {$scale->max_level} for salary scale {$scale->code}."
                    );
                }

                $rank = Rank::withTrashed()
                    ->where('cadre_id', $cadre->id)
                    ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->where('level', $level)
                    ->first();
                $wasExisting = (bool) $rank;

                if ($wasExisting && ! $rank->trashed()) {
                    $skipped++;
                    continue;
                }

                $rank ??= new Rank();
                $rank->fill([
                    'name' => $name,
                    'cadre_id' => $cadre->id,
                    'salary_scale_id' => $scale->id,
                    'level' => $level,
                    'description' => $this->nullable($row['description'] ?? null),
                    'status' => $this->status($row['status'] ?? null, $index),
                ]);

                $rank->save();
                $rank->restore();
                $wasExisting ? $updated++ : $created++;
            }

            return ['type' => 'ranks', 'rows_read' => count($rows), 'created' => $created, 'updated' => $updated, 'skipped' => $skipped];
        });
    }

    protected function stageStaffList(array $rows, User $user): array
    {
        return DB::transaction(function () use ($rows, $user): array {
            $batch = LegacyStaffImportBatch::query()->create([
                'source_database' => 'spreadsheet_upload',
                'source_table' => 'staff_list_upload',
                'created_by' => $user->id,
                'status' => 'staging',
                'started_at' => now(),
                'summary' => $this->emptyStaffListSummary(),
            ]);

            $summary = $this->stageStaffListIntoBatch($rows, $user, $batch);

            $batch->update(['status' => 'staged', 'completed_at' => now(), 'summary' => $summary]);

            return ['type' => 'staff-list', 'batch_id' => $batch->id] + $summary;
        });
    }

    protected function queueStaffList(UploadedFile $file, User $user): array
    {
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'xlsx';
        $storedPath = $file->storeAs('imports/staff-list', Str::uuid().'.'.$extension);
        $batch = LegacyStaffImportBatch::query()->create([
            'source_database' => 'spreadsheet_upload',
            'source_table' => 'staff_list_upload',
            'created_by' => $user->id,
            'status' => 'queued',
            'started_at' => now(),
            'summary' => $this->emptyStaffListSummary([
                'queued_file_path' => $storedPath,
            ]),
        ]);
        $this->dispatchQueuedStaffListImport($batch->id, $storedPath, $user->id);

        return [
            'type' => 'staff-list',
            'batch_id' => $batch->id,
            'status' => 'queued',
        ] + $this->emptyStaffListSummary();
    }

    public function processQueuedStaffListImport(int $batchId, string $storedPath, int $userId): void
    {
        $batch = $this->claimQueuedStaffListBatch($batchId);
        $user = User::query()->find($userId);

        if (! $batch) {
            Log::info('Skipped queued staff-list import because the batch is already being processed or has completed.', [
                'batch_id' => $batchId,
                'stored_path' => $storedPath,
                'user_id' => $userId,
            ]);

            return;
        }

        if (! $user) {
            $this->deleteStoredImport($storedPath);

            return;
        }

        try {
            $this->configureRuntime();
            $this->qualificationCatalogSyncService->syncQualificationTypes();

            $rows = $this->readPopulatedRows(Storage::path($storedPath));
            $summary = DB::transaction(function () use ($rows, $user, $batch): array {
                $lockedBatch = LegacyStaffImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
                $this->clearExistingStagedRows($lockedBatch);

                return $this->stageStaffListIntoBatch($rows, $user, $lockedBatch);
            });

            $batch->update([
                'status' => 'staged',
                'completed_at' => now(),
                'summary' => $this->withoutQueuedFilePath($summary),
            ]);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'The spreadsheet contains no data rows.';

            $batch->update([
                'status' => 'failed',
                'completed_at' => now(),
                'summary' => $this->withoutQueuedFilePath($this->emptyStaffListSummary([
                    'failure_message' => $message,
                    'queued_file_path' => $storedPath,
                ])),
            ]);
        } catch (Throwable $exception) {
            Log::error('Queued staff-list import failed.', [
                'batch_id' => $batchId,
                'stored_path' => $storedPath,
                'user_id' => $userId,
                'exception' => $exception,
            ]);

            $batch->update([
                'status' => 'failed',
                'completed_at' => now(),
                'summary' => $this->withoutQueuedFilePath($this->emptyStaffListSummary([
                    'failure_message' => 'The spreadsheet could not be staged. Review the server logs for details.',
                    'queued_file_path' => $storedPath,
                ])),
            ]);
        } finally {
            $this->deleteStoredImport($storedPath);
        }
    }

    protected function claimQueuedStaffListBatch(int $batchId): ?LegacyStaffImportBatch
    {
        $staleBefore = now()->subSeconds(max(60, (int) config('operational_imports.staff_list_stale_after_seconds', 900)));
        $claimed = LegacyStaffImportBatch::query()
            ->whereKey($batchId)
            ->where(function ($query) use ($staleBefore): void {
                $query
                    ->whereIn('status', ['queued', 'failed'])
                    ->orWhere(function ($stagingQuery) use ($staleBefore): void {
                        $stagingQuery
                            ->where('status', 'staging')
                            ->where('started_at', '<=', $staleBefore);
                    });
            })
            ->update([
                'status' => 'staging',
                'started_at' => now(),
                'completed_at' => null,
            ]);

        if ($claimed === 1) {
            return LegacyStaffImportBatch::query()->find($batchId);
        }

        $batch = LegacyStaffImportBatch::query()->find($batchId);

        if (! $batch) {
            return null;
        }

        if (in_array($batch->status, ['staged', 'completed', 'submitted', 'under_review', 'approved', 'rejected', 'publishing', 'partially_published', 'published'], true)) {
            return null;
        }

        if ($batch->status === 'staging') {
            return null;
        }

        return null;
    }

    protected function clearExistingStagedRows(LegacyStaffImportBatch $batch): void
    {
        LegacyStaffImportError::query()
            ->where('batch_id', $batch->id)
            ->delete();

        LegacyStaffImportRow::query()
            ->where('batch_id', $batch->id)
            ->delete();
    }

    protected function resolveStaffUploadMda(array $sourceRow, User $user, int $index): Mda
    {
        $defaultMda = $this->defaultAccessibleMda($user);

        // Single-MDA users should stage into their only allowed MDA even when
        // the legacy sheet carries a stale or foreign MDA label.
        if ($defaultMda && $user->accessibleMdaIds()->count() === 1) {
            return $defaultMda;
        }

        return $this->resolveMda(['mda_code' => $sourceRow['mda'] ?? null], $user, $index);
    }

    protected function stageStaffListIntoBatch(array $rows, User $user, LegacyStaffImportBatch $batch): array
    {
        $summary = $this->emptyStaffListSummary([
            'rows_read' => count($rows),
        ]);

        foreach ($rows as $index => $sourceRow) {
            if (! $user->hasGlobalMdaAccess()) {
                $resolvedMda = $this->resolveStaffUploadMda($sourceRow, $user, $index);
                $sourceRow['mda'] = $resolvedMda->code;
            }

            $normalizationRow = $sourceRow;
            $normalizationRow['_upload_row'] = $index + 2;
            $normalized = $this->normalizer->normalize($normalizationRow, 'staff_list_upload');
            $issues = $this->validator->validate($normalized);
            $hasErrors = $this->validator->hasErrors($issues);
            $matchedStaff = $this->identityMatcher->match($normalized);
            $status = $hasErrors ? 'invalid' : 'staged';

            $stagedRow = LegacyStaffImportRow::query()->create([
                'batch_id' => $batch->id,
                'legacy_staff_id' => null,
                'legacy_master_staff_id' => null,
                'mda_id' => $normalized['mda_id'],
                'staff_number' => $normalized['staff_number'],
                'legacy_cno' => $normalized['legacy_cno'],
                'legacy_psn' => $normalized['legacy_psn'],
                'legacy_cno_psn' => $normalized['legacy_cno_psn'],
                'full_name' => $normalized['full_name'],
                'raw_payload' => ['source_row' => $sourceRow, 'upload_row' => $index + 2],
                'normalized_payload' => $normalized,
                'dedupe_key' => $normalized['dedupe_key'],
                'status' => $status,
                'matched_staff_id' => $matchedStaff?->id,
                'department_id' => $normalized['department_id'],
                'department_name' => $normalized['department_name'],
                'station_id' => $normalized['station_id'],
                'station_name' => $normalized['station_name'],
                'cadre_id' => $normalized['cadre_id'],
                'cadre_name' => $normalized['cadre_name'],
                'rank_id' => $normalized['rank_id'],
                'rank_name' => $normalized['rank_name'],
                'salary_scale_id' => $normalized['salary_scale_id'],
                'salary_scale_code' => $normalized['salary_scale_code'],
                'level' => $normalized['level'],
                'step' => $normalized['step'],
            ]);

            foreach ($issues as $issue) {
                LegacyStaffImportError::query()->create([
                    'batch_id' => $batch->id,
                    'row_id' => $stagedRow->id,
                    'field' => $issue['field'] ?? null,
                    'error_code' => $issue['error_code'],
                    'message' => $issue['message'],
                    'severity' => $issue['severity'],
                ]);
            }

            $summary['rows_staged']++;
            $summary['rows_with_warnings'] += collect($issues)->contains('severity', 'warning') ? 1 : 0;
            $summary['rows_with_errors'] += $hasErrors ? 1 : 0;
        }

        return $summary;
    }

    protected function emptyStaffListSummary(array $overrides = []): array
    {
        return array_merge([
            'source' => 'staff_list_upload',
            'rows_read' => 0,
            'rows_staged' => 0,
            'rows_published' => 0,
            'rows_with_warnings' => 0,
            'rows_with_errors' => 0,
        ], $overrides);
    }

    protected function shouldStageStaffListInBackground(): bool
    {
        return (bool) config('operational_imports.staff_list_background', true)
            && ! app()->runningUnitTests();
    }

    protected function deleteStoredImport(string $storedPath): void
    {
        if (Storage::exists($storedPath)) {
            Storage::delete($storedPath);
        }
    }

    protected function dispatchQueuedStaffListImport(int $batchId, string $storedPath, int $userId): void
    {
        try {
            $phpBinary = (new PhpExecutableFinder())->find(false) ?: PHP_BINARY;
            Log::info('Dispatching queued staff-list import.', [
                'batch_id' => $batchId,
                'stored_path' => $storedPath,
                'user_id' => $userId,
                'php_binary' => $phpBinary,
                'os_family' => PHP_OS_FAMILY,
            ]);

            if (PHP_OS_FAMILY === 'Windows') {
                $command = sprintf(
                    'start "" /B %s artisan operational-imports:stage-staff-list %s %s %s',
                    escapeshellarg($phpBinary),
                    escapeshellarg((string) $batchId),
                    escapeshellarg($storedPath),
                    escapeshellarg((string) $userId),
                );

                $process = Process::fromShellCommandline($command, base_path());
                $process->run();
            } else {
                $process = new Process([
                    $phpBinary,
                    'artisan',
                    'operational-imports:stage-staff-list',
                    (string) $batchId,
                    $storedPath,
                    (string) $userId,
                ], base_path());

                $process->disableOutput();
                $process->start();
            }
        } catch (Throwable $exception) {
            LegacyStaffImportBatch::query()
                ->whereKey($batchId)
                ->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'summary' => $this->withoutQueuedFilePath($this->emptyStaffListSummary([
                        'failure_message' => 'Unable to start the background staff-list importer.',
                        'queued_file_path' => $storedPath,
                    ])),
                ]);

            $this->deleteStoredImport($storedPath);

            Log::error('Unable to dispatch queued staff-list import.', [
                'batch_id' => $batchId,
                'stored_path' => $storedPath,
                'user_id' => $userId,
                'exception' => $exception,
            ]);

            throw ValidationException::withMessages([
                'file' => 'The background importer could not be started. Please try again.',
            ]);
        }
    }

    protected function withoutQueuedFilePath(array $summary): array
    {
        unset($summary['queued_file_path']);

        return $summary;
    }

    protected function resolveDepartment(array $row, User $user, int $index): Department
    {
        $value = $this->required($row, 'department_code', $index);
        $mda = $this->resolveMda($row, $user, $index);
        $normalizedCode = $this->normalizeLookupCode($value);
        $department = Department::query()
            ->forMda($mda->id)
            ->get()
            ->first(function (Department $department) use ($value, $normalizedCode): bool {
                return strtolower((string) $department->name) === strtolower($value)
                    || $this->normalizeLookupCode($department->code) === $normalizedCode;
            });

        if (! $department || ! $user->canAccessMda((int) $department->mda_id)) {
            $this->rowError($index, 'department_code', 'Department could not be resolved within your accessible MDA.');
        }

        return $department;
    }

    protected function resolveMda(array $row, User $user, int $index): Mda
    {
        $mdaField = array_key_exists('mda_code', $row) ? 'mda_code' : 'mda';

        if ($user->hasGlobalMdaAccess()) {
            $mdaValue = $this->required($row, $mdaField, $index);
            $mda = Mda::query()
                ->whereRaw('LOWER(code) = ?', [strtolower($mdaValue)])
                ->orWhereRaw('LOWER(name) = ?', [strtolower($mdaValue)])
                ->first();

            if (! $mda) {
                $this->rowError($index, $mdaField, 'MDA could not be resolved.');
            }

            return $mda;
        }

        $mdaValue = $this->nullable($row[$mdaField] ?? null);
        $defaultMda = $this->defaultAccessibleMda($user);

        if ($mdaValue === null) {
            if ($defaultMda) {
                return $defaultMda;
            }

            $this->rowError($index, $mdaField, 'No accessible MDA is assigned to this account.');
        }

        $mda = Mda::query()
            ->whereKey($user->accessibleMdaIds()->all())
            ->where(function ($query) use ($mdaValue): void {
                $query
                    ->whereRaw('LOWER(code) = ?', [strtolower($mdaValue)])
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($mdaValue)]);
            })
            ->first();

        if ($mda) {
            return $mda;
        }

        $this->rowError($index, $mdaField, 'Reference data cannot target another MDA.');
    }

    protected function resolveSalaryScale(array $row, int $index, ?int $mdaId = null): SalaryScale
    {
        $code = Str::upper($this->required($row, 'salary_scale_code', $index));
        $scale = SalaryScale::query()
            ->when($mdaId, fn ($query) => $query->forMda((int) $mdaId))
            ->where('code', $code)
            ->first();

        if (! $scale) {
            $this->rowError($index, 'salary_scale_code', 'Salary scale code could not be resolved.');
        }

        return $scale;
    }

    protected function required(array $row, string $key, int $index): string
    {
        $value = $this->nullable($row[$key] ?? null);

        if ($value === null) {
            $this->rowError($index, $key, 'This value is required.');
        }

        return $value;
    }

    protected function status(mixed $value, int $index): string
    {
        $status = strtolower($this->nullable($value) ?? 'active');

        if (! in_array($status, ['active', 'inactive'], true)) {
            $this->rowError($index, 'status', 'Status must be active or inactive.');
        }

        return $status;
    }

    protected function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    protected function normalizeLookupCode(?string $value): ?string
    {
        $value = $this->nullable($value);

        if ($value === null) {
            return null;
        }

        return Str::upper(Str::of($value)->replaceMatches('/[^A-Z0-9]+/i', '')->value()) ?: null;
    }

    protected function describeValueForError(mixed $value): string
    {
        $normalized = $this->nullable($value);

        if ($normalized === null) {
            return '`blank`';
        }

        return '`'.$normalized.'`';
    }

    protected function parseWholeNumber(mixed $value): int|false
    {
        $normalized = $this->nullable($value);

        if ($normalized === null || ! preg_match('/^\d+$/', $normalized)) {
            return false;
        }

        return (int) $normalized;
    }

    protected function rowError(int $index, string $field, string $message): never
    {
        throw ValidationException::withMessages([
            'file' => 'Spreadsheet row '.($index + 2).", {$field}: {$message}",
        ]);
    }

    protected function defaultAccessibleMda(User $user): ?Mda
    {
        if ($user->mda) {
            return $user->mda;
        }

        $mdaId = $user->primaryAccessibleMdaId();

        return $mdaId ? Mda::query()->find($mdaId) : null;
    }
}
