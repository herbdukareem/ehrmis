<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LegacyStaffImportIssueResolutionService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {
    }

    public function applyMapping(LegacyStaffImportRow $row, string $field, int $targetId, User $user, ?string $notes = null): LegacyStaffImportRow
    {
        return DB::transaction(function () use ($row, $field, $targetId, $user, $notes): LegacyStaffImportRow {
            $row->loadMissing('errors');

            $beforePayload = $row->normalized_payload ?? [];
            $beforeRow = $row->toArray();

            [$mapping, $target, $resolvedFields] = $this->resolveTarget($row, $field, $targetId);

            $payload = $row->normalized_payload ?? [];
            foreach ($resolvedFields as $key => $value) {
                $payload[$key] = $value;
            }

            $row->fill(array_merge(
                $this->extractRowSnapshotAttributes($field, $target, $resolvedFields),
                ['normalized_payload' => $payload]
            ));
            $row->save();

            $this->markErrorsResolved(
                $row,
                $mapping['error_code'],
                $user,
                $notes,
                [
                    'field' => $field,
                    'target_id' => $targetId,
                    'target_type' => $target::class,
                ],
            );
            $this->refreshRowStatus($row);
            $row->save();

            $this->auditLogService->log(
                'legacy_staff_import.mapping.resolved',
                $row,
                $beforeRow,
                $row->fresh()?->toArray() ?? $row->toArray(),
                [
                    'field' => $field,
                    'notes' => $notes,
                    'before_payload' => $beforePayload,
                    'after_payload' => $payload,
                    'target' => [
                        'id' => $target->getKey(),
                        'type' => $target::class,
                    ],
                ],
            );

            return $row->fresh(['errors', 'mda', 'department', 'station', 'cadre', 'rank', 'salaryScale', 'matchedStaff', 'publishedStaff']);
        });
    }

    public function ignoreWarning(LegacyStaffImportError $warning, User $user, ?string $notes = null): LegacyStaffImportError
    {
        if ($warning->severity !== 'warning') {
            throw new InvalidArgumentException('Only warning-level issues can be ignored.');
        }

        if ($warning->ignored_at) {
            return $warning;
        }

        $before = $warning->toArray();

        $warning->forceFill([
            'ignored_at' => now(),
            'ignored_by' => $user->id,
            'resolution_notes' => $notes,
            'resolution_context' => array_merge($warning->resolution_context ?? [], [
                'action' => 'ignored_warning',
            ]),
        ])->save();

        $this->auditLogService->log(
            'legacy_staff_import.warning.ignored',
            $warning->row,
            $before,
            $warning->fresh()?->toArray() ?? $warning->toArray(),
            [
                'warning_id' => $warning->id,
                'warning_code' => $warning->error_code,
                'notes' => $notes,
            ],
        );

        return $warning->fresh();
    }

    public function resolveIdentifier(LegacyStaffImportRow $row, string $staffNumber, User $user, ?string $notes = null): LegacyStaffImportRow
    {
        return DB::transaction(function () use ($row, $staffNumber, $user, $notes): LegacyStaffImportRow {
            $staffNumber = trim($staffNumber);

            if ($row->mda_id === null) {
                throw ValidationException::withMessages([
                    'staff_number' => 'Resolve the row MDA before assigning a staff number.',
                ]);
            }

            $liveDuplicate = Staff::withoutGlobalScopes()
                ->where('mda_id', $row->mda_id)
                ->where('staff_number', $staffNumber)
                ->exists();
            $stagedDuplicate = LegacyStaffImportRow::query()
                ->where('mda_id', $row->mda_id)
                ->where('staff_number', $staffNumber)
                ->whereKeyNot($row->id)
                ->exists();

            if ($liveDuplicate || $stagedDuplicate) {
                throw ValidationException::withMessages([
                    'staff_number' => 'This staff number already exists within the row MDA.',
                ]);
            }

            $beforePayload = $row->normalized_payload ?? [];
            $beforeRow = $row->toArray();
            $payload = array_merge($beforePayload, [
                'staff_number' => $staffNumber,
                'dedupe_key' => $staffNumber,
            ]);

            $row->forceFill([
                'staff_number' => $staffNumber,
                'dedupe_key' => $staffNumber,
                'normalized_payload' => $payload,
            ])->save();

            $this->markErrorsResolved($row, 'missing_identifier', $user, $notes, [
                'action' => 'manual_identifier_resolution',
                'staff_number' => $staffNumber,
            ]);
            $this->markErrorsResolved($row, 'provisional_identifier', $user, $notes, [
                'action' => 'manual_identifier_resolution',
                'staff_number' => $staffNumber,
            ]);
            $this->refreshRowStatus($row);
            $row->save();

            $this->auditLogService->log(
                'legacy_staff_import.identifier.resolved',
                $row,
                $beforeRow,
                $row->fresh()?->toArray() ?? $row->toArray(),
                [
                    'notes' => $notes,
                    'before_payload' => $beforePayload,
                    'after_payload' => $payload,
                ],
            );

            return $row->fresh(['errors', 'mda', 'department', 'station', 'cadre', 'rank', 'salaryScale', 'matchedStaff', 'publishedStaff']);
        });
    }

    protected function resolveTarget(LegacyStaffImportRow $row, string $field, int $targetId): array
    {
        return match ($field) {
            'mda' => $this->resolveMdaTarget($targetId),
            'department' => $this->resolveDepartmentTarget($row, $targetId),
            'station' => $this->resolveStationTarget($row, $targetId),
            'cadre' => $this->resolveCadreTarget($row, $targetId),
            'rank' => $this->resolveRankTarget($row, $targetId),
            'qualification_type' => $this->resolveQualificationTypeTarget($targetId),
            default => throw new InvalidArgumentException('Unsupported mapping field `'.$field.'`.'),
        };
    }

    protected function resolveMdaTarget(int $targetId): array
    {
        $target = Mda::query()->findOrFail($targetId);

        return [[
            'error_code' => 'missing_mda',
        ], $target, [
            'mda_id' => $target->id,
            'mda_name' => $target->name,
        ]];
    }

    protected function resolveDepartmentTarget(LegacyStaffImportRow $row, int $targetId): array
    {
        $target = Department::query()->findOrFail($targetId);

        if ($row->mda_id !== null && (int) $target->mda_id !== (int) $row->mda_id) {
            throw new InvalidArgumentException('Department mapping must remain within the row MDA.');
        }

        return [[
            'error_code' => 'missing_department',
        ], $target, [
            'department_id' => $target->id,
            'department_name' => $target->name,
        ]];
    }

    protected function resolveStationTarget(LegacyStaffImportRow $row, int $targetId): array
    {
        $target = Station::withoutGlobalScopes()->findOrFail($targetId);

        if ($row->mda_id !== null && (int) $target->mda_id !== (int) $row->mda_id) {
            throw new InvalidArgumentException('Station mapping must remain within the row MDA.');
        }

        return [[
            'error_code' => 'missing_station',
        ], $target, [
            'station_id' => $target->id,
            'station_name' => $target->name,
        ]];
    }

    protected function resolveCadreTarget(LegacyStaffImportRow $row, int $targetId): array
    {
        $target = Cadre::query()->with('department')->findOrFail($targetId);

        if ($row->mda_id !== null && (int) $target->department?->mda_id !== (int) $row->mda_id) {
            throw new InvalidArgumentException('Cadre mapping must remain within the row MDA.');
        }

        return [[
            'error_code' => 'missing_cadre',
        ], $target, [
            'cadre_id' => $target->id,
            'cadre_name' => $target->name,
            'salary_scale_id' => $target->salary_scale_id,
        ]];
    }

    protected function resolveRankTarget(LegacyStaffImportRow $row, int $targetId): array
    {
        $target = Rank::query()->with('cadre.department')->findOrFail($targetId);

        if ($row->mda_id !== null && (int) $target->cadre?->department?->mda_id !== (int) $row->mda_id) {
            throw new InvalidArgumentException('Rank mapping must remain within the row MDA.');
        }

        return [[
            'error_code' => 'missing_rank',
        ], $target, [
            'rank_id' => $target->id,
            'rank_name' => $target->name,
            'cadre_id' => $target->cadre_id,
            'cadre_name' => $target->cadre?->name,
            'salary_scale_id' => $target->salary_scale_id,
            'level' => $target->level,
        ]];
    }

    protected function resolveQualificationTypeTarget(int $targetId): array
    {
        $target = QualificationType::query()->findOrFail($targetId);

        return [[
            'error_code' => 'missing_qualification',
        ], $target, [
            'qualification_type_id' => $target->id,
            'qualification_code' => $target->code,
        ]];
    }

    protected function extractRowSnapshotAttributes(string $field, Model $target, array $resolvedFields): array
    {
        $attributes = [];

        foreach ([
            'mda_id',
            'staff_number',
            'legacy_cno',
            'legacy_psn',
            'legacy_cno_psn',
            'full_name',
            'department_id',
            'department_name',
            'station_id',
            'station_name',
            'cadre_id',
            'cadre_name',
            'rank_id',
            'rank_name',
            'salary_scale_id',
            'salary_scale_code',
            'level',
            'step',
        ] as $column) {
            if (array_key_exists($column, $resolvedFields)) {
                $attributes[$column] = $resolvedFields[$column];
            }
        }

        if ($field === 'mda' && isset($resolvedFields['mda_name'])) {
            $attributes['mda_id'] = $resolvedFields['mda_id'];
        }

        if (($attributes['salary_scale_id'] ?? null) && ! isset($attributes['salary_scale_code'])) {
            $attributes['salary_scale_code'] = optional($target->salaryScale)->code;
        }

        return $attributes;
    }

    protected function markErrorsResolved(
        LegacyStaffImportRow $row,
        string $errorCode,
        User $user,
        ?string $notes,
        array $context,
    ): void {
        $row->errors()
            ->where('error_code', $errorCode)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolved_by' => $user->id,
                'resolution_notes' => $notes,
                'resolution_context' => json_encode($context),
                'updated_at' => now(),
            ]);
    }

    protected function refreshRowStatus(LegacyStaffImportRow $row): void
    {
        if ($row->published_staff_id) {
            $row->status = 'published';

            return;
        }

        $hasBlockingErrors = $row->errors()
            ->where('severity', 'error')
            ->whereNull('resolved_at')
            ->exists();

        $row->status = $hasBlockingErrors ? 'invalid' : 'staged';
    }
}
