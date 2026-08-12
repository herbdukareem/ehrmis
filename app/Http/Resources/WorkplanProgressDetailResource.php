<?php

namespace App\Http\Resources;

use App\Enums\WorkplanProgressStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Workplan\Models\WorkplanProgressReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkplanProgressDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $editable = in_array($this->status, [WorkplanProgressStatus::DRAFT, WorkplanProgressStatus::RETURNED], true);
        $history = AuditLog::query()->with('actor:id,name')->where('auditable_type', WorkplanProgressReport::class)->where('auditable_id', $this->id)->whereIn('event_code', ['workplan.progress.created', 'workplan.progress.submitted', 'workplan.progress.returned', 'workplan.progress.resubmitted', 'workplan.progress.verified'])->oldest('occurred_at')->get();

        return [
            'id' => $this->id,
            'period' => $this->period?->value,
            'status' => $this->status?->value,
            'workplan_id' => $this->workplan_id,
            'reported_expenditure' => $this->reported_expenditure,
            'achievement_summary' => $this->achievement_summary,
            'challenges' => $this->challenges,
            'corrective_action' => $this->corrective_action,
            'next_period_action' => $this->next_period_action,
            'remarks' => $this->remarks,
            'return_reason' => $this->return_reason,
            'prepared_by' => $this->preparedBy?->only(['id', 'name']),
            'submitted_by' => $this->submittedBy?->only(['id', 'name']),
            'verified_by' => $this->verifiedBy?->only(['id', 'name']),
            'returned_by' => $this->returnedBy?->only(['id', 'name']),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'verified_at' => $this->verified_at?->toISOString(),
            'returned_at' => $this->returned_at?->toISOString(),
            'history' => $history->map(fn ($entry) => ['event'=>$entry->event_code, 'actor'=>$entry->actor?->only(['id', 'name']), 'occurred_at'=>$entry->occurred_at?->toISOString(), 'reason'=>$entry->after_values['return_reason'] ?? null]),
            'activity' => ['id'=>$this->activity?->id, 'code'=>$this->activity?->activity_code, 'title'=>$this->activity?->title, 'department'=>$this->activity?->department?->only(['id','name']), 'responsible_staff'=>$this->activity?->responsibleStaff?->only(['id','full_name','staff_number'])],
            'indicators' => $this->indicators->map(fn ($progress) => ['id'=>$progress->id, 'indicator_id'=>$progress->workplan_indicator_id, 'code'=>$progress->indicator?->code, 'indicator'=>$progress->indicator?->indicator, 'unit'=>$progress->indicator?->unit, 'target_value_snapshot'=>$progress->target_value_snapshot, 'actual_value'=>$progress->actual_value, 'achievement_ratio'=>$progress->achievement_ratio, 'verification_note'=>$progress->verification_note]),
            'evidence' => $this->evidence->map(fn ($evidence) => ['id'=>$evidence->id, 'title'=>$evidence->title, 'evidence_type'=>$evidence->evidence_type, 'external_url'=>$evidence->external_url, 'notes'=>$evidence->notes, 'uploaded_by'=>$evidence->uploadedBy?->only(['id','name']), 'created_at'=>$evidence->created_at?->toISOString(), 'download_url'=>$evidence->file_path ? route('api.workplan-evidence.download', $evidence) : null]),
            'can' => [
                'edit' => $editable && ($user?->can('update', $this->resource) ?? false),
                'submit' => $editable && ($user?->can('submit', $this->resource) ?? false),
                'verify' => $this->status === WorkplanProgressStatus::SUBMITTED && ($user?->can('verify', $this->resource) ?? false),
                'return' => $this->status === WorkplanProgressStatus::SUBMITTED && ($user?->can('return', $this->resource) ?? false),
                'add_evidence' => $editable && ($user?->can('update', $this->resource) ?? false),
                'delete_evidence' => $editable && ($user?->can('update', $this->resource) ?? false),
            ],
        ];
    }
}
