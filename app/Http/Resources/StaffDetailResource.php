<?php

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\SalaryCalculationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Staff $staff */
        $staff = $this->resource;
        $importRow = $this->resolveImportRow($staff);
        $salarySummary = $this->resolveSalarySummary($staff);
        $auditLogs = AuditLog::query()
            ->where('auditable_type', Staff::class)
            ->where('auditable_id', $staff->id)
            ->latest('occurred_at')
            ->limit(5)
            ->get();

        return [
            'id' => $staff->id,
            'staff_number' => $staff->staff_number,
            'legacy_cno' => $staff->legacy_cno,
            'legacy_psn' => $staff->legacy_psn,
            'legacy_cno_psn' => $staff->legacy_cno_psn,
            'legacy_staff_id' => $staff->legacy_staff_id,
            'legacy_master_staff_id' => $staff->legacy_master_staff_id,
            'mda' => $staff->mda?->only(['id', 'code', 'name']),
            'surname' => $staff->surname,
            'first_name' => $staff->first_name,
            'middle_name' => $staff->middle_name,
            'full_name' => $staff->full_name,
            'sex' => $staff->sex,
            'date_of_birth' => optional($staff->date_of_birth)?->toDateString(),
            'status' => $staff->status,
            'passport_url' => $staff->passport_path ? route('api.staff.passport.show', $staff, false) : null,
            'personal_detail' => $staff->personalDetail?->only([
                'lga',
                'state_of_origin',
                'phone',
                'email',
                'address',
                'marital_status',
                'file_no',
            ]),
            'current_employment' => $staff->currentEmployment ? StaffEmploymentResource::make($staff->currentEmployment)->resolve() : null,
            'current_salary_placement' => $staff->currentSalaryPlacement ? StaffSalaryPlacementResource::make($staff->currentSalaryPlacement)->resolve() : null,
            'salary_summary' => $salarySummary,
            'qualifications' => StaffQualificationResource::collection($staff->qualifications)->resolve(),
            'allowance_assignments' => StaffAllowanceAssignmentResource::collection($staff->allowanceAssignments)->resolve(),
            'status_histories' => StaffStatusHistoryResource::collection($staff->statusHistories)->resolve(),
            'documents' => $staff->documents->map(fn ($document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'document_type' => $document->document_type,
                'notes' => $document->notes,
                'created_at' => $document->created_at?->toDateTimeString(),
                'compiled_pdf_url' => $document->compiled_pdf_path
                    ? route('api.staff.documents.compiled-pdf.show', [$staff, $document], false)
                    : null,
                'compiled_pdf_size' => $document->compiled_pdf_size,
                'pages' => $document->pages->map(fn ($page): array => [
                    'id' => $page->id,
                    'page_number' => $page->page_number,
                    'original_name' => $page->original_name,
                    'mime_type' => $page->mime_type,
                    'file_size' => $page->file_size,
                    'preview_url' => route('api.staff.documents.pages.show', [$staff, $document, $page], false),
                ])->values()->all(),
            ])->values()->all(),
            'import_metadata' => [
                'latest_batch_id' => $importRow?->batch_id,
                'latest_batch_status' => $importRow?->batch?->status,
                'row_status' => $importRow?->status,
                'warnings' => $importRow?->errors?->map(fn ($error) => [
                    'code' => $error->error_code,
                    'message' => $error->message,
                ])->values()->all() ?? [],
                'needs_call_allowance_clarification' => $importRow?->errors?->contains('error_code', 'call_allowance_unresolved') ?? false,
            ],
            'audit_summary' => [
                'count' => AuditLog::query()
                    ->where('auditable_type', Staff::class)
                    ->where('auditable_id', $staff->id)
                    ->count(),
                'latest_events' => $auditLogs->map(fn ($log) => [
                    'event_code' => $log->event_code,
                    'occurred_at' => optional($log->occurred_at)?->toDateTimeString(),
                ])->values()->all(),
            ],
        ];
    }

    protected function resolveSalarySummary(Staff $staff): array
    {
        $placement = $staff->currentSalaryPlacement;

        if (! $placement || ! $placement->salaryScale) {
            return [];
        }

        $eligibleAllowanceCodes = $staff->allowanceAssignments
            ->filter(fn ($assignment): bool => (bool) $assignment->is_eligible && $assignment->allowanceType !== null)
            ->pluck('allowanceType.code')
            ->filter()
            ->values()
            ->all();

        $calculation = app(SalaryCalculationService::class)->calculateGrossForPlacement(
            $placement->salaryScale->code,
            (int) $placement->level,
            (int) $placement->step,
            $eligibleAllowanceCodes,
        );

        return [
            'basic_salary' => $placement->basic_salary_snapshot !== null ? (float) $placement->basic_salary_snapshot : $calculation['basic_salary'],
            'legacy_gross_salary' => $placement->legacy_gross_salary_snapshot !== null ? (float) $placement->legacy_gross_salary_snapshot : $calculation['legacy_gross_salary'],
            'calculated_gross_salary' => $placement->calculated_gross_salary_snapshot !== null ? (float) $placement->calculated_gross_salary_snapshot : $calculation['calculated_gross'],
            'gross_difference' => $placement->gross_difference_snapshot !== null ? (float) $placement->gross_difference_snapshot : $calculation['gross_difference'],
            'allowance_breakdown' => $calculation['allowance_breakdown'],
        ];
    }

    protected function resolveImportRow(Staff $staff): ?LegacyStaffImportRow
    {
        return LegacyStaffImportRow::query()
            ->with(['batch', 'errors'])
            ->where(function ($query) use ($staff): void {
                $query
                    ->where('published_staff_id', $staff->id)
                    ->orWhere('matched_staff_id', $staff->id);

                if ($staff->legacy_cno_psn) {
                    $query->orWhere('dedupe_key', $staff->legacy_cno_psn);
                }
            })
            ->latest('id')
            ->first();
    }
}
