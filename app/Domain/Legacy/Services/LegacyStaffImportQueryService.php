<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LegacyStaffImportQueryService
{
    public function paginateBatches(array $filters, User $user, int $perPage = 15): LengthAwarePaginator
    {
        $query = LegacyStaffImportBatch::query()
            ->with(['approvalWorkflow.steps'])
            ->when(! $user->hasGlobalMdaAccess(), function (Builder $query) use ($user): void {
                $query->whereHas('rows', fn (Builder $rowQuery) => $rowQuery->where('mda_id', $user->mda_id));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['source_table'] ?? null, fn (Builder $query, string $sourceTable) => $query->where('source_table', $sourceTable))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $dateFrom) => $query->whereDate('started_at', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $dateTo) => $query->whereDate('started_at', '<=', $dateTo))
            ->withCount([
                'rows as visible_rows_count' => fn (Builder $rowQuery) => $this->scopeRowsForUser($rowQuery, $user),
                'rows as visible_published_rows_count' => fn (Builder $rowQuery) => $this->scopeRowsForUser($rowQuery, $user)
                    ->whereNotNull('published_staff_id'),
                'errors as visible_warnings_count' => fn (Builder $errorQuery) => $errorQuery
                    ->where('severity', 'warning')
                    ->whereHas('row', fn (Builder $rowQuery) => $this->scopeRowsForUser($rowQuery, $user)),
                'errors as visible_errors_count' => fn (Builder $errorQuery) => $errorQuery
                    ->where('severity', 'error')
                    ->whereHas('row', fn (Builder $rowQuery) => $this->scopeRowsForUser($rowQuery, $user)),
            ])
            ->latest('id');

        return $query->paginate(min(100, max(1, $perPage)))->withQueryString();
    }

    public function summarizeBatch(LegacyStaffImportBatch $batch, User $user): array
    {
        $rowsQuery = $batch->rows()->getQuery();
        $this->scopeRowsForUser($rowsQuery, $user);

        $rowStatusCounts = (clone $rowsQuery)
            ->selectRaw('status, COUNT(*) as aggregate_count')
            ->groupBy('status')
            ->pluck('aggregate_count', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $errorsQuery = LegacyStaffImportError::query()
            ->where('batch_id', $batch->id)
            ->whereHas('row', fn (Builder $rowQuery) => $this->scopeRowsForUser($rowQuery, $user));

        $severityCounts = (clone $errorsQuery)
            ->selectRaw('severity, COUNT(*) as aggregate_count')
            ->groupBy('severity')
            ->pluck('aggregate_count', 'severity')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $issueCodeCounts = (clone $errorsQuery)
            ->selectRaw('error_code, COUNT(*) as aggregate_count')
            ->groupBy('error_code')
            ->orderByDesc('aggregate_count')
            ->pluck('aggregate_count', 'error_code')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $referenceCodes = [
            'missing_mda',
            'missing_department',
            'missing_station',
            'missing_cadre',
            'missing_rank',
            'missing_salary_scale',
            'missing_qualification',
            'missing_level',
            'missing_step',
        ];

        $unresolvedReferenceCount = (clone $errorsQuery)
            ->whereIn('error_code', $referenceCodes)
            ->whereNull('resolved_at')
            ->whereNull('ignored_at')
            ->count();

        $unresolvedCallAllowanceCount = (clone $errorsQuery)
            ->where('error_code', 'call_allowance_unresolved')
            ->whereNull('ignored_at')
            ->count();

        return [
            'row_status_counts' => $rowStatusCounts,
            'severity_counts' => $severityCounts,
            'issue_code_counts' => $issueCodeCounts,
            'rows_staged' => (clone $rowsQuery)->count(),
            'rows_published' => (clone $rowsQuery)->whereNotNull('published_staff_id')->count(),
            'rows_publishable' => (clone $rowsQuery)->whereNull('published_staff_id')->whereDoesntHave('errors', function (Builder $query): void {
                $query->where('severity', 'error')->whereNull('resolved_at');
            })->count(),
            'warnings_count' => (int) ($severityCounts['warning'] ?? 0),
            'errors_count' => (int) ($severityCounts['error'] ?? 0),
            'unresolved_reference_count' => $unresolvedReferenceCount,
            'unresolved_call_allowance_count' => $unresolvedCallAllowanceCount,
            'reference_issue_counts' => array_filter(
                collect($issueCodeCounts)
                    ->only($referenceCodes)
                    ->map(fn ($count): int => (int) $count)
                    ->all()
            ),
        ];
    }

    public function paginateRows(LegacyStaffImportBatch $batch, array $filters, User $user, int $perPage = 20): LengthAwarePaginator
    {
        $query = LegacyStaffImportRow::query()
            ->with(['mda', 'department', 'station', 'cadre', 'rank', 'salaryScale', 'matchedStaff', 'publishedStaff', 'errors'])
            ->where('batch_id', $batch->id);

        $this->scopeRowsForUser($query, $user);

        $query
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $innerQuery) use ($search): void {
                    $like = '%'.$search.'%';
                    $innerQuery
                        ->where('full_name', 'like', $like)
                        ->orWhere('staff_number', 'like', $like)
                        ->orWhere('legacy_cno', 'like', $like)
                        ->orWhere('legacy_psn', 'like', $like)
                        ->orWhere('legacy_cno_psn', 'like', $like);
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['mda_id'] ?? null, fn (Builder $query, $mdaId) => $query->where('mda_id', $mdaId))
            ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['station_id'] ?? null, fn (Builder $query, $stationId) => $query->where('station_id', $stationId))
            ->when($filters['salary_scale_id'] ?? null, fn (Builder $query, $salaryScaleId) => $query->where('salary_scale_id', $salaryScaleId))
            ->when($filters['cadre_id'] ?? null, fn (Builder $query, $cadreId) => $query->where('cadre_id', $cadreId))
            ->when($filters['rank_id'] ?? null, fn (Builder $query, $rankId) => $query->where('rank_id', $rankId))
            ->when($filters['level'] ?? null, fn (Builder $query, $level) => $query->where('level', $level))
            ->when(($filters['published'] ?? '') !== '', function (Builder $query) use ($filters): void {
                if ((string) $filters['published'] === '1') {
                    $query->whereNotNull('published_staff_id');
                } elseif ((string) $filters['published'] === '0') {
                    $query->whereNull('published_staff_id');
                }
            })
            ->when((bool) ($filters['publishable'] ?? false), fn (Builder $query) => $query
                ->whereNull('published_staff_id')
                ->whereDoesntHave('errors', fn (Builder $errorQuery) => $errorQuery
                    ->where('severity', 'error')
                    ->whereNull('resolved_at')))
            ->when($filters['severity'] ?? null, fn (Builder $query, string $severity) => $query->whereHas('errors', function (Builder $errorQuery) use ($severity): void {
                $errorQuery->where('severity', $severity);
            }))
            ->when($filters['warning_code'] ?? null, fn (Builder $query, string $warningCode) => $query->whereHas('errors', function (Builder $errorQuery) use ($warningCode): void {
                $errorQuery->where('severity', 'warning')->where('error_code', $warningCode);
            }))
            ->when($filters['error_code'] ?? null, fn (Builder $query, string $errorCode) => $query->whereHas('errors', function (Builder $errorQuery) use ($errorCode): void {
                $errorQuery->where('error_code', $errorCode);
            }))
            ->when((bool) ($filters['missing_mda'] ?? false), fn (Builder $query) => $query->whereHas('errors', fn (Builder $errorQuery) => $this->scopeUnresolvedIssueCode($errorQuery, 'missing_mda')))
            ->when((bool) ($filters['missing_department'] ?? false), fn (Builder $query) => $query->whereHas('errors', fn (Builder $errorQuery) => $this->scopeUnresolvedIssueCode($errorQuery, 'missing_department')))
            ->when((bool) ($filters['missing_station'] ?? false), fn (Builder $query) => $query->whereHas('errors', fn (Builder $errorQuery) => $this->scopeUnresolvedIssueCode($errorQuery, 'missing_station')))
            ->when((bool) ($filters['missing_cadre'] ?? false), fn (Builder $query) => $query->whereHas('errors', fn (Builder $errorQuery) => $this->scopeUnresolvedIssueCode($errorQuery, 'missing_cadre')))
            ->when((bool) ($filters['missing_rank'] ?? false), fn (Builder $query) => $query->whereHas('errors', fn (Builder $errorQuery) => $this->scopeUnresolvedIssueCode($errorQuery, 'missing_rank')))
            ->when((bool) ($filters['unresolved_call_allowance'] ?? false), fn (Builder $query) => $query->whereHas('errors', fn (Builder $errorQuery) => $this->scopeUnresolvedIssueCode($errorQuery, 'call_allowance_unresolved')))
            ->latest('id');

        return $query->paginate(min(100, max(1, $perPage)))->withQueryString();
    }

    public function issueOptionsForBatch(LegacyStaffImportBatch $batch, User $user): array
    {
        $issues = LegacyStaffImportError::query()
            ->where('batch_id', $batch->id)
            ->whereHas('row', fn (Builder $rowQuery) => $this->scopeRowsForUser($rowQuery, $user))
            ->select('severity', 'error_code')
            ->distinct()
            ->orderBy('error_code')
            ->get();

        return [
            'warning_codes' => $issues->where('severity', 'warning')->pluck('error_code')->values()->all(),
            'error_codes' => $issues->where('severity', 'error')->pluck('error_code')->values()->all(),
        ];
    }

    public function batchStatusOptions(): array
    {
        return ['pending', 'staging', 'staged', 'completed', 'submitted', 'under_review', 'approved', 'rejected', 'publishing', 'partially_published', 'published'];
    }

    public function sourceTableOptions(): Collection
    {
        return LegacyStaffImportBatch::query()
            ->select('source_table')
            ->distinct()
            ->orderBy('source_table')
            ->pluck('source_table');
    }

    protected function scopeRowsForUser(Builder $query, User $user): Builder
    {
        if ($user->hasGlobalMdaAccess()) {
            return $query;
        }

        return $query->where('mda_id', $user->mda_id);
    }

    protected function scopeUnresolvedIssueCode(Builder $query, string $issueCode): Builder
    {
        return $query
            ->where('error_code', $issueCode)
            ->whereNull('resolved_at')
            ->whereNull('ignored_at');
    }
}
