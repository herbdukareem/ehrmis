<?php

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LegacyStaffImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LegacyStaffImportRow $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'batch_id' => $row->batch_id,
            'legacy_staff_id' => $row->legacy_staff_id,
            'matched_staff_id' => $row->matched_staff_id,
            'published_staff_id' => $row->published_staff_id,
            'status' => $row->status,
            'dedupe_key' => $row->dedupe_key,
            'staff_number' => $row->staff_number,
            'full_name' => $row->full_name,
            'legacy_cno' => $row->legacy_cno,
            'legacy_psn' => $row->legacy_psn,
            'legacy_cno_psn' => $row->legacy_cno_psn,
            'mda' => $row->mda ? [
                'id' => $row->mda->id,
                'code' => $row->mda->code,
                'name' => $row->mda->name,
            ] : [
                'id' => $row->mda_id,
                'code' => null,
                'name' => data_get($row->normalized_payload, 'mda_name'),
            ],
            'department' => [
                'id' => $row->department_id,
                'name' => $row->department?->name ?? $row->department_name ?? data_get($row->normalized_payload, 'department_name'),
            ],
            'station' => [
                'id' => $row->station_id,
                'name' => $row->station?->name ?? $row->station_name ?? data_get($row->normalized_payload, 'station_name'),
            ],
            'salary_scale' => [
                'id' => $row->salary_scale_id,
                'code' => $row->salaryScale?->code ?? $row->salary_scale_code ?? data_get($row->normalized_payload, 'salary_scale_code'),
            ],
            'level' => $row->level ?? data_get($row->normalized_payload, 'level'),
            'step' => $row->step ?? data_get($row->normalized_payload, 'step'),
            'cadre' => [
                'id' => $row->cadre_id,
                'name' => $row->cadre?->name ?? $row->cadre_name ?? data_get($row->normalized_payload, 'cadre_name'),
            ],
            'rank' => [
                'id' => $row->rank_id,
                'name' => $row->rank?->name ?? $row->rank_name ?? data_get($row->normalized_payload, 'rank_name'),
            ],
            'warnings' => LegacyStaffImportErrorResource::collection($row->errors->where('severity', 'warning'))->resolve(),
            'errors' => LegacyStaffImportErrorResource::collection($row->errors->where('severity', 'error'))->resolve(),
            'raw_payload' => $row->raw_payload,
            'normalized_payload' => $row->normalized_payload,
            'issue_summary' => [
                'warnings_count' => $row->errors->where('severity', 'warning')->count(),
                'errors_count' => $row->errors->where('severity', 'error')->count(),
                'unresolved_call_allowance' => $row->errors->contains(fn ($error) => $error->error_code === 'call_allowance_unresolved' && $error->ignored_at === null),
            ],
            'publication_status' => [
                'is_published' => $row->published_staff_id !== null,
                'published_staff' => $row->publishedStaff ? [
                    'id' => $row->publishedStaff->id,
                    'staff_number' => $row->publishedStaff->staff_number,
                    'full_name' => $row->publishedStaff->full_name,
                ] : null,
                'matched_staff' => $row->matchedStaff ? [
                    'id' => $row->matchedStaff->id,
                    'staff_number' => $row->matchedStaff->staff_number,
                    'full_name' => $row->matchedStaff->full_name,
                ] : null,
            ],
            'audit_history' => AuditLog::query()
                ->where('auditable_type', LegacyStaffImportRow::class)
                ->where('auditable_id', $row->id)
                ->latest('occurred_at')
                ->limit(10)
                ->get()
                ->map(fn (AuditLog $log): array => [
                    'event_code' => $log->event_code,
                    'occurred_at' => $log->occurred_at?->toDateTimeString(),
                    'context' => $log->context,
                ])
                ->all(),
        ];
    }
}
