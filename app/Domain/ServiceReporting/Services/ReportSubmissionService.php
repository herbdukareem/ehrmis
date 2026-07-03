<?php

namespace App\Domain\ServiceReporting\Services;

use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Station;
use App\Domain\ServiceReporting\Models\ReportSubmission;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Domain\ServiceReporting\Models\ReportTemplateIndicator;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportSubmissionService
{
    public function __construct(
        protected ReportingPeriodService $periods,
        protected ModuleAccessService $moduleAccess,
        protected AuditLogService $auditLogService,
    ) {
    }

    public function createDraft(ReportTemplate $template, array $data, User $actor): ReportSubmission
    {
        $mdaId = (int) $data['mda_id'];
        $this->assertUserCan($actor, 'create-service-reports', $mdaId);
        $this->assertTemplateAssigned($template, $mdaId, $data['station_id'] ?? null);

        $period = $this->periods->fromPeriodString(
            $template->frequency,
            $data['period'],
            $template->submission_deadline_day,
        );

        return DB::transaction(function () use ($template, $data, $actor, $period, $mdaId): ReportSubmission {
            $submission = ReportSubmission::query()->firstOrCreate(
                [
                    'report_template_id' => $template->id,
                    'reporting_period_id' => $period->id,
                    'mda_id' => $mdaId,
                    'station_id' => $data['station_id'] ?? null,
                ],
                [
                    'department_id' => $data['department_id'] ?? null,
                    'status' => 'draft',
                    'is_late' => $period->submission_due_date ? now()->toDateString() > $period->submission_due_date->toDateString() : false,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ],
            );

            if (! $submission->wasRecentlyCreated && ! $submission->canEditValues()) {
                throw ValidationException::withMessages([
                    'submission' => 'A submission already exists for this template, period, MDA, and station.',
                ]);
            }

            if ($submission->wasRecentlyCreated) {
                $this->recordAction($submission, 'draft_created', $actor, null, 'draft');
                $this->audit('service_reporting.submission.draft_created', $submission, [], $submission->toArray(), $actor);
            }

            $fresh = $submission->fresh(['template.sections.indicators.dimensions', 'period', 'mda', 'station', 'values', 'reviews']);
            $fresh->wasRecentlyCreated = $submission->wasRecentlyCreated;

            return $fresh;
        });
    }

    public function saveDraft(ReportSubmission $submission, array $values, User $actor): ReportSubmission
    {
        $this->assertUserCan($actor, 'create-service-reports', (int) $submission->mda_id);
        abort_unless($submission->canEditValues(), 403, 'Only draft or returned submissions can be edited.');

        return DB::transaction(function () use ($submission, $values, $actor): ReportSubmission {
            $before = $submission->values()->get()->toArray();
            $submission->loadMissing('template.sections.indicators.dimensions');
            $submission->values()->delete();

            foreach ($this->normalizeValues($submission->template->sections->flatMap->indicators, $values) as $row) {
                $submission->values()->create($row);
            }

            $submission->forceFill([
                'summary' => $this->summary($submission->fresh('values')),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit('service_reporting.submission.values_updated', $submission, $before, $submission->values()->get()->toArray(), $actor);

            return $submission->fresh(['template.sections.indicators.dimensions', 'period', 'mda', 'station', 'values', 'reviews']);
        });
    }

    public function submit(ReportSubmission $submission, User $actor, ?string $comment = null): ReportSubmission
    {
        $this->assertUserCan($actor, 'submit-service-reports', (int) $submission->mda_id);
        abort_unless(in_array($submission->status, ['draft', 'returned'], true), 403, 'Only draft or returned submissions can be submitted.');
        $this->validateRequiredValues($submission);

        return $this->transition($submission, 'submitted', 'submitted', $actor, $comment, [
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
            'return_reason' => null,
        ]);
    }

    public function review(ReportSubmission $submission, User $actor, ?string $comment = null): ReportSubmission
    {
        $this->assertUserCan($actor, 'review-service-reports', (int) $submission->mda_id);
        abort_unless($submission->status === 'submitted', 403, 'Only submitted reports can be moved under review.');

        return $this->transition($submission, 'reviewed', 'under_review', $actor, $comment, [
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);
    }

    public function returnForCorrection(ReportSubmission $submission, User $actor, string $reason): ReportSubmission
    {
        $this->assertUserCan($actor, 'return-service-reports', (int) $submission->mda_id);
        abort_unless(in_array($submission->status, ['submitted', 'under_review'], true), 403, 'Only submitted reports can be returned.');

        return $this->transition($submission, 'returned', 'returned', $actor, $reason, [
            'returned_by' => $actor->id,
            'returned_at' => now(),
            'return_reason' => $reason,
        ]);
    }

    public function approve(ReportSubmission $submission, User $actor, ?string $comment = null): ReportSubmission
    {
        $this->assertUserCan($actor, 'approve-service-reports', (int) $submission->mda_id);
        abort_unless(in_array($submission->status, ['submitted', 'under_review'], true), 403, 'Only submitted or reviewed reports can be approved.');

        return $this->transition($submission, 'approved', 'approved', $actor, $comment, [
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);
    }

    public function lock(ReportSubmission $submission, User $actor, ?string $comment = null): ReportSubmission
    {
        $this->assertUserCan($actor, 'lock-service-reports', (int) $submission->mda_id);
        abort_unless($submission->status === 'approved', 403, 'Only approved reports can be locked.');

        return $this->transition($submission, 'locked', 'locked', $actor, $comment, [
            'locked_by' => $actor->id,
            'locked_at' => now(),
        ]);
    }

    public function reopen(ReportSubmission $submission, User $actor, ?string $comment = null): ReportSubmission
    {
        $this->assertUserCan($actor, 'return-service-reports', (int) $submission->mda_id);
        abort_unless(in_array($submission->status, ['returned', 'approved'], true), 403, 'Only returned or approved reports can be reopened.');

        return $this->transition($submission, 'reopened', 'draft', $actor, $comment, [
            'return_reason' => null,
        ]);
    }

    protected function transition(ReportSubmission $submission, string $action, string $status, User $actor, ?string $comment, array $extra = []): ReportSubmission
    {
        return DB::transaction(function () use ($submission, $action, $status, $actor, $comment, $extra): ReportSubmission {
            $before = $submission->toArray();
            $beforeStatus = $submission->status;
            $submission->forceFill([...$extra, 'status' => $status, 'updated_by' => $actor->id])->save();
            $this->recordAction($submission, $action, $actor, $beforeStatus, $status, $comment);
            $this->audit("service_reporting.submission.{$action}", $submission, $before, $submission->fresh()->toArray(), $actor);

            return $submission->fresh(['template.sections.indicators.dimensions', 'period', 'mda', 'station', 'values', 'reviews']);
        });
    }

    protected function normalizeValues(Collection $indicators, array $values): array
    {
        $byCode = $indicators->keyBy('code');
        $rows = [];

        foreach ($values as $value) {
            $indicator = $byCode[$value['indicator_code'] ?? ''] ?? null;
            if (! $indicator instanceof ReportTemplateIndicator) {
                throw ValidationException::withMessages(['values' => 'Unknown indicator code submitted.']);
            }

            $dimensions = $indicator->dimensions;
            if ($dimensions->isNotEmpty()) {
                foreach ($dimensions as $dimension) {
                    $submittedDimensions = $value['dimensions'][$dimension->dimension_key] ?? [];
                    foreach ($submittedDimensions as $dimensionValue => $rawValue) {
                        if (! in_array($dimensionValue, $dimension->dimension_values, true)) {
                            throw ValidationException::withMessages(['values' => 'Submitted dimension value is not configured for this indicator.']);
                        }
                        $rows[] = $this->valueRow($indicator, $rawValue, $dimension->dimension_key, $dimensionValue);
                    }
                }

                continue;
            }

            $rows[] = $this->valueRow($indicator, $value['value'] ?? null);
        }

        return $rows;
    }

    protected function valueRow(ReportTemplateIndicator $indicator, mixed $rawValue, ?string $dimensionKey = null, ?string $dimensionValue = null): array
    {
        if ($rawValue === '' || $rawValue === null) {
            $rawValue = null;
        }

        $this->validateTypedValue($indicator, $rawValue);

        return [
            'report_template_indicator_id' => $indicator->id,
            'indicator_code' => $indicator->code,
            'dimension_key' => $dimensionKey,
            'dimension_value' => $dimensionValue,
            'value_integer' => $indicator->value_type === 'integer' && $rawValue !== null ? (int) $rawValue : null,
            'value_decimal' => in_array($indicator->value_type, ['decimal', 'percentage'], true) && $rawValue !== null ? (float) $rawValue : null,
            'value_text' => $indicator->value_type === 'text' ? $rawValue : null,
            'value_boolean' => $indicator->value_type === 'boolean' && $rawValue !== null ? (bool) $rawValue : null,
            'computed_value_decimal' => null,
        ];
    }

    protected function validateTypedValue(ReportTemplateIndicator $indicator, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if ($indicator->value_type === 'integer' && (! is_numeric($value) || (int) $value < 0)) {
            throw ValidationException::withMessages(['values' => "{$indicator->label} must be a non-negative integer."]);
        }

        if (in_array($indicator->value_type, ['decimal', 'percentage'], true) && (! is_numeric($value) || (float) $value < 0)) {
            throw ValidationException::withMessages(['values' => "{$indicator->label} must be a non-negative number."]);
        }

        if ($indicator->value_type === 'percentage' && (float) $value > 100) {
            throw ValidationException::withMessages(['values' => "{$indicator->label} must be between 0 and 100."]);
        }
    }

    protected function validateRequiredValues(ReportSubmission $submission): void
    {
        $submission->loadMissing('template.sections.indicators.dimensions', 'values');
        $valueGroups = $submission->values->groupBy('indicator_code');

        foreach ($submission->template->sections->flatMap->indicators as $indicator) {
            if (! $indicator->is_required) {
                continue;
            }

            $values = $valueGroups[$indicator->code] ?? collect();

            if ($indicator->dimensions->isEmpty() && $values->isEmpty()) {
                throw ValidationException::withMessages(['values' => "{$indicator->label} is required before submission."]);
            }

            foreach ($indicator->dimensions as $dimension) {
                if (! $dimension->is_required) {
                    continue;
                }

                foreach ($dimension->dimension_values as $dimensionValue) {
                    if (! $values->contains(fn ($row): bool => $row->dimension_key === $dimension->dimension_key && $row->dimension_value === $dimensionValue)) {
                        throw ValidationException::withMessages(['values' => "{$indicator->label} requires {$dimension->dimension_label}: {$dimensionValue}."]);
                    }
                }
            }
        }
    }

    protected function summary(ReportSubmission $submission): array
    {
        return $submission->values
            ->groupBy('indicator_code')
            ->map(fn (Collection $values): float|int => $values->sum(fn ($value): float => (float) ($value->value_integer ?? $value->value_decimal ?? 0)))
            ->all();
    }

    protected function assertTemplateAssigned(ReportTemplate $template, int $mdaId, ?int $stationId): void
    {
        $assigned = $template->assignments()
            ->active()
            ->where('mda_id', $mdaId)
            ->where(function ($query) use ($stationId): void {
                $query->whereNull('station_id');
                if ($stationId) {
                    $query->orWhere('station_id', $stationId);
                }
            })
            ->exists();

        if (! $assigned) {
            throw ValidationException::withMessages(['template_id' => 'This template is not assigned to the selected MDA or station.']);
        }

        if ($stationId && ! Station::query()->whereKey($stationId)->where('mda_id', $mdaId)->exists()) {
            throw ValidationException::withMessages(['station_id' => 'The selected station does not belong to the selected MDA.']);
        }
    }

    protected function assertUserCan(User $actor, string $permission, int $mdaId): void
    {
        abort_unless(
            $this->moduleAccess->userCan($actor, 'service_reporting', $permission, $mdaId),
            403,
            'You do not have access to this service reporting action.'
        );
    }

    protected function recordAction(ReportSubmission $submission, string $action, User $actor, ?string $beforeStatus, string $afterStatus, ?string $comment = null): void
    {
        $submission->reviews()->create([
            'action' => $action,
            'comment' => $comment,
            'actor_user_id' => $actor->id,
            'acted_at' => now(),
            'before_status' => $beforeStatus,
            'after_status' => $afterStatus,
        ]);
    }

    protected function audit(string $event, ReportSubmission $submission, array $before, array $after, User $actor): void
    {
        $submission->loadMissing('template', 'period');
        $this->auditLogService->log($event, $submission, $before, $after, [
            'source' => 'service_reporting',
            'template_id' => $submission->report_template_id,
            'template_code' => $submission->template?->code,
            'submission_id' => $submission->id,
            'mda_id' => $submission->mda_id,
            'station_id' => $submission->station_id,
            'period' => $submission->period?->label(),
            'actor_user_id' => $actor->id,
        ]);
    }
}
