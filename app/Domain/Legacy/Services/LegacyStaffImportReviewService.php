<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportRow;

class LegacyStaffImportReviewService
{
    /**
     * @return array<string, mixed>
     */
    public function review(?int $batchId = null, int $sampleLimit = 10, ?string $issueCode = null): array
    {
        $batch = $batchId
            ? LegacyStaffImportBatch::query()->findOrFail($batchId)
            : LegacyStaffImportBatch::query()->latest('id')->firstOrFail();

        $rowStatusCounts = LegacyStaffImportRow::query()
            ->where('batch_id', $batch->id)
            ->selectRaw('status, COUNT(*) as aggregate_count')
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('aggregate_count', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $severityCounts = LegacyStaffImportError::query()
            ->where('batch_id', $batch->id)
            ->selectRaw('severity, COUNT(*) as aggregate_count')
            ->groupBy('severity')
            ->orderBy('severity')
            ->pluck('aggregate_count', 'severity')
            ->map(fn ($count) => (int) $count)
            ->all();

        $issueCodeCounts = LegacyStaffImportError::query()
            ->where('batch_id', $batch->id)
            ->selectRaw('error_code, COUNT(*) as aggregate_count')
            ->groupBy('error_code')
            ->orderByDesc('aggregate_count')
            ->orderBy('error_code')
            ->pluck('aggregate_count', 'error_code')
            ->map(fn ($count) => (int) $count)
            ->all();

        $sampleIssueQuery = LegacyStaffImportError::query()
            ->with(['row'])
            ->where('batch_id', $batch->id)
            ->orderByDesc('id');

        if ($issueCode !== null) {
            $sampleIssueQuery->where('error_code', $issueCode);
        }

        $sampleIssues = $sampleIssueQuery
            ->limit(max(1, $sampleLimit))
            ->get()
            ->map(function (LegacyStaffImportError $error): array {
                return [
                    'row_id' => $error->row_id,
                    'dedupe_key' => $error->row?->dedupe_key,
                    'severity' => $error->severity,
                    'error_code' => $error->error_code,
                    'message' => $error->message,
                ];
            })
            ->all();

        $summary = $batch->summary ?? [];

        return [
            'batch' => $batch,
            'summary' => $summary,
            'row_status_counts' => $rowStatusCounts,
            'severity_counts' => $severityCounts,
            'issue_code_counts' => $issueCodeCounts,
            'sample_issues' => $sampleIssues,
            'issue_code_filter' => $issueCode,
            'unresolved_reference_counts' => array_filter([
                'missing_mda' => (int) ($summary['missing_mda'] ?? 0),
                'missing_department' => (int) ($summary['missing_department'] ?? 0),
                'missing_station' => (int) ($summary['missing_station'] ?? 0),
                'missing_cadre' => (int) ($summary['missing_cadre'] ?? 0),
                'missing_rank' => (int) ($summary['missing_rank'] ?? 0),
                'missing_salary_scale' => (int) ($summary['missing_salary_scale'] ?? 0),
                'missing_qualification' => (int) ($summary['missing_qualification'] ?? 0),
                'missing_level' => (int) ($summary['missing_level'] ?? 0),
                'missing_step' => (int) ($summary['missing_step'] ?? 0),
            ]),
        ];
    }
}
