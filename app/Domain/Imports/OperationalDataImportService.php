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
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class OperationalDataImportService
{
    public const TYPES = ['stations', 'highest-qualifications', 'cadres', 'ranks', 'staff-list'];

    public function __construct(
        protected LegacyStaffRowNormalizer $normalizer,
        protected LegacyStaffRowValidator $validator,
        protected LegacyStaffIdentityMatcher $identityMatcher,
    ) {
    }

    public function import(string $type, UploadedFile $file, User $user): array
    {
        $this->configureRuntime();
        $rows = $this->readRows($file);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The spreadsheet contains no data rows.']);
        }

        return match ($type) {
            'stations' => $this->importStations($rows, $user),
            'highest-qualifications' => $this->importHighestQualifications($rows, $user),
            'cadres' => $this->importCadres($rows, $user),
            'ranks' => $this->importRanks($rows, $user),
            'staff-list' => $this->stageStaffList($rows, $user),
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
            'stations' => new SpreadsheetTemplateExport(
                ['code', 'name', 'mda_code', 'description', 'status'],
                [['HQ', 'Headquarters', $defaultMda?->code ?? 'MOH', 'Main administrative station', 'active']],
            ),
            'highest-qualifications' => new SpreadsheetTemplateExport(
                ['code', 'name', 'mda_code', 'description', 'status'],
                [['MBBS', 'Bachelor of Medicine, Bachelor of Surgery', $defaultMda?->code ?? 'MOH', 'Medical degree', 'active']],
            ),
            'cadres' => new SpreadsheetTemplateExport(
                ['name', 'mda_code', 'department_code', 'salary_scale_code', 'description', 'status'],
                [['Medical Officer', $defaultMda?->code ?? 'MOH', $defaultDepartmentCode, 'CM', 'Medical cadre', 'active']],
            ),
            'ranks' => new SpreadsheetTemplateExport(
                ['name', 'cadre_name', 'mda_code', 'department_code', 'salary_scale_code', 'level', 'description', 'status'],
                [['Senior Medical Officer', 'Medical Officer', $defaultMda?->code ?? 'MOH', $defaultDepartmentCode, 'CM', 4, 'Senior clinical rank', 'active']],
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
                    '2010-02-01', '2024-01-01', '2045-01-31', 'MBBS', 'MBBS',
                    'General Medicine', 'Clinical', 0, 0, 1, 0, 0, 0, 'CALLDOC',
                ]],
            ),
            default => throw ValidationException::withMessages(['type' => 'Unsupported template type.']),
        };
    }

    protected function readRows(UploadedFile $file): array
    {
        $import = new SpreadsheetRowsImport();
        Excel::import($import, $file);

        return $import->rows;
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

    protected function importHighestQualifications(array $rows, User $user): array
    {
        return DB::transaction(function () use ($rows, $user): array {
            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($rows as $index => $row) {
                $mda = $this->resolveMda($row, $user, $index);
                $code = Str::upper($this->required($row, 'code', $index));
                $name = $this->required($row, 'name', $index);
                $qualificationByCode = QualificationType::query()
                    ->forMda($mda->id)
                    ->whereRaw('LOWER(code) = ?', [strtolower($code)])
                    ->first();
                $qualificationByName = QualificationType::query()
                    ->forMda($mda->id)
                    ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->first();

                if ($qualificationByCode && $qualificationByName && ! $qualificationByCode->is($qualificationByName)) {
                    $this->rowError($index, 'name', 'The qualification code and name belong to different existing qualification types.');
                }

                $qualification = $qualificationByCode ?? $qualificationByName;
                $wasExisting = (bool) $qualification;

                if ($wasExisting) {
                    $skipped++;
                    continue;
                }

                $qualification ??= new QualificationType();
                $qualification->fill([
                    'mda_id' => $mda->id,
                    'code' => $code,
                    'name' => $name,
                    'description' => $this->nullable($row['description'] ?? null),
                    'status' => $this->status($row['status'] ?? null, $index),
                ]);

                $qualification->save();
                $wasExisting ? $updated++ : $created++;
            }

            return [
                'type' => 'highest-qualifications',
                'rows_read' => count($rows),
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ];
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
                $department = $this->resolveDepartment($row, $user, $index);
                $scale = $this->resolveSalaryScale($row, $index, (int) $department->mda_id);
                $cadreName = $this->required($row, 'cadre_name', $index);
                $cadre = Cadre::query()
                    ->where('department_id', $department->id)
                    ->where('salary_scale_id', $scale->id)
                    ->whereRaw('LOWER(name) = ?', [strtolower($cadreName)])
                    ->first();

                if (! $cadre) {
                    $this->rowError($index, 'cadre_name', 'Cadre could not be resolved within the selected department and salary scale.');
                }

                $level = filter_var($row['level'] ?? null, FILTER_VALIDATE_INT);

                if ($level === false || $level < $scale->min_level || $level > $scale->max_level) {
                    $this->rowError($index, 'level', "Level must be between {$scale->min_level} and {$scale->max_level}.");
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
            $summary = [
                'source' => 'staff_list_upload',
                'rows_read' => count($rows),
                'rows_staged' => 0,
                'rows_published' => 0,
                'rows_with_warnings' => 0,
                'rows_with_errors' => 0,
            ];
            $batch = LegacyStaffImportBatch::query()->create([
                'source_database' => 'spreadsheet_upload',
                'source_table' => 'staff_list_upload',
                'status' => 'staging',
                'started_at' => now(),
            ]);

            foreach ($rows as $index => $sourceRow) {
                if (! $user->hasGlobalMdaAccess()) {
                    $resolvedMda = $this->resolveMda(['mda_code' => $sourceRow['mda'] ?? null], $user, $index);
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

            $batch->update(['status' => 'staged', 'completed_at' => now(), 'summary' => $summary]);

            return ['type' => 'staff-list', 'batch_id' => $batch->id] + $summary;
        });
    }

    protected function resolveDepartment(array $row, User $user, int $index): Department
    {
        $value = $this->required($row, 'department_code', $index);
        $mda = $this->resolveMda($row, $user, $index);
        $query = Department::query()->forMda($mda->id);

        $department = $query
            ->where(fn ($query) => $query->whereRaw('LOWER(code) = ?', [strtolower($value)])->orWhereRaw('LOWER(name) = ?', [strtolower($value)]))
            ->first();

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
