<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LegacyStaffImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = $this->summary ?? [];

        return [
            'id' => $this->id,
            'source_database' => $this->source_database,
            'source_table' => $this->source_table,
            'status' => $this->status,
            'started_at' => $this->started_at?->toDateTimeString(),
            'completed_at' => $this->completed_at?->toDateTimeString(),
            'rows_read' => (int) ($summary['rows_read'] ?? 0),
            'rows_staged' => (int) ($this->visible_rows_count ?? $summary['rows_staged'] ?? 0),
            'rows_published' => (int) ($this->visible_published_rows_count ?? $summary['rows_published'] ?? 0),
            'warnings_count' => (int) ($this->visible_warnings_count ?? $summary['rows_with_warnings'] ?? 0),
            'errors_count' => (int) ($this->visible_errors_count ?? $summary['rows_with_errors'] ?? 0),
            'unresolved_reference_count' => (int) ($this->unresolved_reference_count ?? (
                ($summary['missing_mda'] ?? 0)
                + ($summary['missing_department'] ?? 0)
                + ($summary['missing_station'] ?? 0)
                + ($summary['missing_cadre'] ?? 0)
                + ($summary['missing_rank'] ?? 0)
                + ($summary['missing_salary_scale'] ?? 0)
                + ($summary['missing_qualification'] ?? 0)
                + ($summary['missing_level'] ?? 0)
                + ($summary['missing_step'] ?? 0)
            )),
            'unresolved_call_allowance_count' => (int) ($this->unresolved_call_allowance_count ?? $summary['call_allowance_unresolved'] ?? 0),
            'summary' => $summary,
            'approval_workflow' => $this->approvalWorkflow ? [
                'id' => $this->approvalWorkflow->id,
                'status' => $this->approvalWorkflow->status,
                'workflow_type' => $this->approvalWorkflow->workflow_type,
                'submitted_at' => $this->approvalWorkflow->submitted_at?->toDateTimeString(),
                'approved_at' => $this->approvalWorkflow->approved_at?->toDateTimeString(),
                'rejected_at' => $this->approvalWorkflow->rejected_at?->toDateTimeString(),
                'rejection_comment' => $this->approvalWorkflow->rejection_comment,
                'current_step_no' => $this->approvalWorkflow->current_step_no,
                'steps' => $this->approvalWorkflow->steps->map(fn ($step): array => [
                    'step_no' => $step->step_no,
                    'status' => $step->status,
                    'reviewer_role' => $step->reviewer_role,
                    'reviewer_user_id' => $step->reviewer_user_id,
                    'acted_at' => $step->acted_at?->toDateTimeString(),
                    'comment' => $step->comment,
                ])->values()->all(),
            ] : null,
        ];
    }
}
