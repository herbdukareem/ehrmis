<?php

namespace App\Http\Controllers\Api;

use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\ServiceReporting\Models\ReportSubmission;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Domain\ServiceReporting\Models\ReportTemplateIndicator;
use App\Domain\ServiceReporting\Models\ReportTemplateSection;
use App\Domain\ServiceReporting\Services\ReportAnalyticsService;
use App\Domain\ServiceReporting\Services\ReportExportService;
use App\Domain\ServiceReporting\Services\ReportSubmissionService;
use App\Domain\ServiceReporting\Services\ReportTemplateAssignmentService;
use App\Domain\ServiceReporting\Services\ReportTemplateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceReporting\AnalyticsRequest;
use App\Http\Requests\ServiceReporting\SaveReportDraftRequest;
use App\Http\Requests\ServiceReporting\StoreReportSubmissionRequest;
use App\Http\Requests\ServiceReporting\StoreReportTemplateRequest;
use App\Http\Requests\ServiceReporting\SyncTemplateAssignmentsRequest;
use App\Http\Requests\ServiceReporting\UpdateReportTemplateRequest;
use App\Http\Requests\ServiceReporting\WorkflowReportSubmissionRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ServiceReportingController extends Controller
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
        protected ReportTemplateService $templates,
        protected ReportTemplateAssignmentService $assignments,
        protected ReportSubmissionService $submissions,
        protected ReportAnalyticsService $analytics,
        protected ReportExportService $exports,
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('view-service-reports'), 403);

        $visibleMdaIds = $this->visibleMdaIds($request);
        $submissionQuery = ReportSubmission::query()
            ->whereIn('mda_id', $visibleMdaIds)
            ->with(['template', 'period', 'mda', 'station', 'submitter', 'creator'])
            ->latest();

        return response()->json(['data' => [
            'summary' => [
                'draft' => (clone $submissionQuery)->where('status', 'draft')->count(),
                'submitted' => (clone $submissionQuery)->whereIn('status', ['submitted', 'under_review'])->count(),
                'returned' => (clone $submissionQuery)->where('status', 'returned')->count(),
                'approved' => (clone $submissionQuery)->where('status', 'approved')->count(),
                'locked' => (clone $submissionQuery)->where('status', 'locked')->count(),
            ],
            'templates' => $this->assignments->availableTemplatesFor($request->user())->map(fn (ReportTemplate $template): array => $this->templatePayload($template, false))->values(),
            'pending_submissions' => $submissionQuery->whereIn('status', ['submitted', 'under_review', 'returned'])->limit(10)->get()->map(fn (ReportSubmission $submission): array => $this->submissionPayload($submission))->values(),
            'compliance' => $this->analytics->compliance($request->all(), $request->user()),
            'mdas' => Mda::query()->visibleToUser($request->user())->orderBy('name')->get(['id', 'code', 'name']),
            'stations' => Station::query()
                ->when(! $request->user()->hasGlobalMdaAccess(), fn ($query) => $query->whereIn('mda_id', $request->user()->accessibleMdaIds()->all()))
                ->orderBy('name')
                ->get(['id', 'mda_id', 'code', 'name']),
            'departments' => Department::query()
                ->when(! $request->user()->hasGlobalMdaAccess(), fn ($query) => $query->whereIn('mda_id', $request->user()->accessibleMdaIds()->all()))
                ->orderBy('name')
                ->get(['id', 'mda_id', 'code', 'name']),
        ]]);
    }

    public function templates(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('view-service-reports'), 403);
        $mdaId = $request->integer('mda_id') ?: null;

        if ($mdaId) {
            abort_unless($request->user()->canAccessMda($mdaId), 403);
        }

        if ($request->user()->can('manage-report-templates') || $request->user()->can('assign-report-templates')) {
            $visibleMdaIds = $request->user()->accessibleMdaIds()->all();

            $templates = ReportTemplate::query()
                ->with(['ownerMda', 'sections.indicators.dimensions', 'assignments.mda', 'assignments.station'])
                ->when($mdaId, function ($query) use ($mdaId): void {
                    $query->where(function ($templateQuery) use ($mdaId): void {
                        $templateQuery
                            ->where('owner_mda_id', $mdaId)
                            ->orWhereHas('assignments', fn ($assignmentQuery) => $assignmentQuery->where('mda_id', $mdaId));
                    });
                })
                ->when(! $request->user()->hasGlobalMdaAccess(), function ($query) use ($visibleMdaIds): void {
                    $query->where(function ($templateQuery) use ($visibleMdaIds): void {
                        $templateQuery
                            ->whereIn('owner_mda_id', $visibleMdaIds)
                            ->orWhereHas('assignments', fn ($assignmentQuery) => $assignmentQuery->whereIn('mda_id', $visibleMdaIds));
                    });
                })
                ->orderBy('name')
                ->get();

            return response()->json([
                'data' => $templates->map(fn (ReportTemplate $template): array => $this->templatePayload($template))->values(),
            ]);
        }

        return response()->json([
            'data' => $this->assignments
                ->availableTemplatesFor($request->user(), $mdaId)
                ->map(fn (ReportTemplate $template): array => $this->templatePayload($template))
                ->values(),
        ]);
    }

    public function storeTemplate(StoreReportTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->create($request->validated(), $request->user());

        return response()->json(['message' => 'Report template created.', 'data' => $this->templatePayload($template)], 201);
    }

    public function showTemplate(Request $request, ReportTemplate $template): JsonResponse
    {
        abort_unless($request->user()->can('view-service-reports') && (
            $this->assignments->userCanSeeTemplate($request->user(), $template)
            || $this->userCanManageTemplate($request, $template)
        ), 403);

        return response()->json(['data' => $this->templatePayload($template->load(['sections.indicators.dimensions', 'assignments.mda', 'assignments.station', 'ownerMda']))]);
    }

    public function updateTemplate(UpdateReportTemplateRequest $request, ReportTemplate $template): JsonResponse
    {
        $template = $this->templates->update($template, $request->validated(), $request->user());

        return response()->json(['message' => 'Report template updated.', 'data' => $this->templatePayload($template)]);
    }

    public function activateTemplate(Request $request, ReportTemplate $template): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-templates'), 403);

        return response()->json(['message' => 'Report template activated.', 'data' => $this->templatePayload($this->templates->activate($template, $request->user()))]);
    }

    public function deactivateTemplate(Request $request, ReportTemplate $template): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-templates'), 403);

        return response()->json(['message' => 'Report template deactivated.', 'data' => $this->templatePayload($this->templates->deactivate($template, $request->user()))]);
    }

    public function storeSection(Request $request, ReportTemplate $template): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-templates'), 403);
        $section = $this->templates->addSection($template, $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', 'alpha_dash'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]));

        return response()->json(['message' => 'Section created.', 'data' => $this->sectionPayload($section)], 201);
    }

    public function updateSection(Request $request, ReportTemplate $template, ReportTemplateSection $section): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-templates') && (int) $section->report_template_id === (int) $template->id, 403);
        $section = $this->templates->updateSection($section, $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', 'alpha_dash'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]));

        return response()->json(['message' => 'Section updated.', 'data' => $this->sectionPayload($section)]);
    }

    public function deleteSection(Request $request, ReportTemplate $template, ReportTemplateSection $section): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-templates') && (int) $section->report_template_id === (int) $template->id, 403);
        $section->delete();

        return response()->json(['message' => 'Section deleted.']);
    }

    public function storeIndicator(Request $request, ReportTemplate $template, ReportTemplateSection $section): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-templates') && (int) $section->report_template_id === (int) $template->id, 403);
        $indicator = $this->templates->addIndicator($section, $this->indicatorPayload($request));

        return response()->json(['message' => 'Indicator created.', 'data' => $this->indicatorResponse($indicator)], 201);
    }

    public function updateIndicator(Request $request, ReportTemplate $template, ReportTemplateSection $section, ReportTemplateIndicator $indicator): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-templates') && (int) $section->report_template_id === (int) $template->id && (int) $indicator->report_template_section_id === (int) $section->id, 403);
        $indicator = $this->templates->updateIndicator($indicator, $this->indicatorPayload($request));

        return response()->json(['message' => 'Indicator updated.', 'data' => $this->indicatorResponse($indicator)]);
    }

    public function deleteIndicator(Request $request, ReportTemplate $template, ReportTemplateSection $section, ReportTemplateIndicator $indicator): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-templates') && (int) $section->report_template_id === (int) $template->id && (int) $indicator->report_template_section_id === (int) $section->id, 403);
        $indicator->delete();

        return response()->json(['message' => 'Indicator deleted.']);
    }

    public function assignments(Request $request, ReportTemplate $template): JsonResponse
    {
        abort_unless($request->user()->can('view-service-reports') && $this->assignments->userCanSeeTemplate($request->user(), $template), 403);

        return response()->json(['data' => $template->assignments()->with(['mda', 'station', 'department'])->get()->map(fn ($assignment): array => $this->assignmentPayload($assignment))->values()]);
    }

    public function syncAssignments(SyncTemplateAssignmentsRequest $request, ReportTemplate $template): JsonResponse
    {
        $assignments = $this->assignments->syncAssignments($template, $request->validated('assignments'), $request->user());

        return response()->json(['message' => 'Template assignments updated.', 'data' => $assignments->map(fn ($assignment): array => $this->assignmentPayload($assignment))->values()]);
    }

    public function submissions(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('view-service-reports'), 403);

        $query = ReportSubmission::query()
            ->with(['template', 'period', 'mda', 'station', 'submitter', 'creator', 'reviewer', 'approver', 'locker'])
            ->when(! $request->user()->hasGlobalMdaAccess(), fn ($builder) => $builder->whereIn('mda_id', $request->user()->accessibleMdaIds()->all()))
            ->when($request->integer('template_id'), fn ($builder, $templateId) => $builder->where('report_template_id', $templateId))
            ->when($request->integer('mda_id'), fn ($builder, $mdaId) => $builder->where('mda_id', $mdaId))
            ->when($request->integer('station_id'), fn ($builder, $stationId) => $builder->where('station_id', $stationId))
            ->when($request->query('status'), fn ($builder, $status) => $builder->whereIn('status', explode(',', (string) $status)))
            ->when($request->integer('year'), fn ($builder, $year) => $builder->whereHas('period', fn ($period) => $period->where('period_year', $year)))
            ->when($request->integer('month'), fn ($builder, $month) => $builder->whereHas('period', fn ($period) => $period->where('period_month', $month)))
            ->latest();

        return response()->json(['data' => $query->limit(100)->get()->map(fn (ReportSubmission $submission): array => $this->submissionPayload($submission))->values()]);
    }

    public function storeSubmission(StoreReportSubmissionRequest $request): JsonResponse
    {
        $template = ReportTemplate::query()->findOrFail($request->validated('template_id'));
        $submission = $this->submissions->createDraft($template, $request->validated(), $request->user());

        return response()->json(['message' => 'Draft submission ready.', 'data' => $this->submissionPayload($submission, true)], $submission->wasRecentlyCreated ? 201 : 200);
    }

    public function showSubmission(Request $request, ReportSubmission $submission): JsonResponse
    {
        abort_unless($this->canViewMda($request, (int) $submission->mda_id), 403);

        return response()->json(['data' => $this->submissionPayload($submission->load(['template.sections.indicators.dimensions', 'period', 'mda', 'station', 'values', 'reviews.actor']), true)]);
    }

    public function saveDraft(SaveReportDraftRequest $request, ReportSubmission $submission): JsonResponse
    {
        $submission = $this->submissions->saveDraft($submission, $request->validated('values'), $request->user());

        return response()->json(['message' => 'Draft values saved.', 'data' => $this->submissionPayload($submission, true)]);
    }

    public function submit(WorkflowReportSubmissionRequest $request, ReportSubmission $submission): JsonResponse
    {
        return response()->json(['message' => 'Report submitted.', 'data' => $this->submissionPayload($this->submissions->submit($submission, $request->user(), $request->validated('comment')), true)]);
    }

    public function review(WorkflowReportSubmissionRequest $request, ReportSubmission $submission): JsonResponse
    {
        return response()->json(['message' => 'Report moved under review.', 'data' => $this->submissionPayload($this->submissions->review($submission, $request->user(), $request->validated('comment')), true)]);
    }

    public function returnSubmission(WorkflowReportSubmissionRequest $request, ReportSubmission $submission): JsonResponse
    {
        $reason = $request->validated('reason') ?: $request->validated('comment') ?: 'Returned for correction.';

        return response()->json(['message' => 'Report returned for correction.', 'data' => $this->submissionPayload($this->submissions->returnForCorrection($submission, $request->user(), $reason), true)]);
    }

    public function approve(WorkflowReportSubmissionRequest $request, ReportSubmission $submission): JsonResponse
    {
        return response()->json(['message' => 'Report approved.', 'data' => $this->submissionPayload($this->submissions->approve($submission, $request->user(), $request->validated('comment')), true)]);
    }

    public function lock(WorkflowReportSubmissionRequest $request, ReportSubmission $submission): JsonResponse
    {
        return response()->json(['message' => 'Report locked.', 'data' => $this->submissionPayload($this->submissions->lock($submission, $request->user(), $request->validated('comment')), true)]);
    }

    public function reopen(WorkflowReportSubmissionRequest $request, ReportSubmission $submission): JsonResponse
    {
        return response()->json(['message' => 'Report reopened.', 'data' => $this->submissionPayload($this->submissions->reopen($submission, $request->user(), $request->validated('comment')), true)]);
    }

    public function indicators(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('view-service-reports'), 403);
        $template = ReportTemplate::query()->where('code', $request->query('template_code'))->with('sections.indicators')->firstOrFail();
        abort_unless($this->assignments->userCanSeeTemplate($request->user(), $template), 403);

        return response()->json(['data' => $template->sections->flatMap->indicators->map(fn ($indicator): array => $this->indicatorResponse($indicator))->values()]);
    }

    public function trends(AnalyticsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->trend($request->validated(), $request->user())]);
    }

    public function exportSubmission(Request $request, ReportSubmission $submission): BinaryFileResponse
    {
        abort_unless($request->user()->can('export-service-reports') && $this->canViewMda($request, (int) $submission->mda_id), 403);

        return $this->exports->submission($submission, $request->user());
    }

    public function exportAnalytics(AnalyticsRequest $request): BinaryFileResponse
    {
        abort_unless($request->user()->can('export-service-reports'), 403);

        return $this->exports->analytics($this->analytics->trend($request->validated(), $request->user()), $request->user());
    }

    public function compliance(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('view-service-reports'), 403);

        return response()->json(['data' => $this->analytics->compliance($request->all(), $request->user())]);
    }

    protected function visibleMdaIds(Request $request): array
    {
        return Mda::query()->visibleToUser($request->user())->pluck('id')->all();
    }

    protected function canViewMda(Request $request, int $mdaId): bool
    {
        return $this->moduleAccess->userCan($request->user(), 'service_reporting', 'view-service-reports', $mdaId);
    }

    protected function userCanManageTemplate(Request $request, ReportTemplate $template): bool
    {
        if (! ($request->user()->can('manage-report-templates') || $request->user()->can('assign-report-templates'))) {
            return false;
        }

        if ($request->user()->hasGlobalMdaAccess()) {
            return true;
        }

        $visibleMdaIds = $request->user()->accessibleMdaIds()->all();
        $template->loadMissing('assignments');

        return in_array((int) $template->owner_mda_id, $visibleMdaIds, true)
            || $template->assignments->contains(fn ($assignment): bool => in_array((int) $assignment->mda_id, $visibleMdaIds, true));
    }

    protected function templatePayload(ReportTemplate $template, bool $deep = true): array
    {
        $template->loadMissing(['ownerMda', 'assignments.mda', 'assignments.station', 'assignments.department']);
        if ($deep) {
            $template->loadMissing('sections.indicators.dimensions');
        }

        return [
            'id' => $template->id,
            'owner_mda_id' => $template->owner_mda_id,
            'owner_mda' => $template->ownerMda?->only(['id', 'code', 'name']),
            'name' => $template->name,
            'code' => $template->code,
            'description' => $template->description,
            'frequency' => $template->frequency,
            'status' => $template->status,
            'requires_approval' => $template->requires_approval,
            'submission_deadline_day' => $template->submission_deadline_day,
            'allow_late_submission' => $template->allow_late_submission,
            'sections' => $deep ? $template->sections->map(fn ($section): array => $this->sectionPayload($section))->values() : [],
            'assignments' => $template->assignments->map(fn ($assignment): array => $this->assignmentPayload($assignment))->values(),
        ];
    }

    protected function sectionPayload(ReportTemplateSection $section): array
    {
        $section->loadMissing('indicators.dimensions');

        return [
            'id' => $section->id,
            'title' => $section->title,
            'code' => $section->code,
            'description' => $section->description,
            'sort_order' => $section->sort_order,
            'indicators' => $section->indicators->map(fn ($indicator): array => $this->indicatorResponse($indicator))->values(),
        ];
    }

    protected function indicatorResponse(ReportTemplateIndicator $indicator): array
    {
        $indicator->loadMissing('dimensions');

        return [
            'id' => $indicator->id,
            'code' => $indicator->code,
            'label' => $indicator->label,
            'description' => $indicator->description,
            'value_type' => $indicator->value_type,
            'unit' => $indicator->unit,
            'is_required' => $indicator->is_required,
            'is_computed' => $indicator->is_computed,
            'compute_formula' => $indicator->compute_formula,
            'validation_rules' => $indicator->validation_rules,
            'sort_order' => $indicator->sort_order,
            'status' => $indicator->status,
            'dimensions' => $indicator->dimensions->map(fn ($dimension): array => [
                'id' => $dimension->id,
                'dimension_key' => $dimension->dimension_key,
                'dimension_label' => $dimension->dimension_label,
                'dimension_values' => $dimension->dimension_values,
                'is_required' => $dimension->is_required,
                'total_strategy' => $dimension->total_strategy,
                'sort_order' => $dimension->sort_order,
            ])->values(),
        ];
    }

    protected function assignmentPayload($assignment): array
    {
        return [
            'id' => $assignment->id,
            'mda_id' => $assignment->mda_id,
            'mda' => $assignment->mda?->only(['id', 'code', 'name']),
            'station_id' => $assignment->station_id,
            'station' => $assignment->station?->only(['id', 'code', 'name']),
            'department_id' => $assignment->department_id,
            'department' => $assignment->department?->only(['id', 'code', 'name']),
            'facility_type' => $assignment->facility_type,
            'required_from' => $assignment->required_from,
            'required_until' => $assignment->required_until,
            'is_required' => $assignment->is_required,
            'status' => $assignment->status,
        ];
    }

    protected function submissionPayload(ReportSubmission $submission, bool $deep = false): array
    {
        $submission->loadMissing(['template', 'period', 'mda', 'station', 'submitter', 'creator', 'reviewer', 'approver', 'locker']);
        if ($deep) {
            $submission->loadMissing(['template.sections.indicators.dimensions', 'values', 'reviews.actor']);
        }

        return [
            'id' => $submission->id,
            'template_id' => $submission->report_template_id,
            'template' => $submission->template ? ['id' => $submission->template->id, 'code' => $submission->template->code, 'name' => $submission->template->name] : null,
            'period_id' => $submission->reporting_period_id,
            'period' => $submission->period ? ['id' => $submission->period->id, 'label' => $submission->period->label(), 'year' => $submission->period->period_year, 'month' => $submission->period->period_month] : null,
            'mda_id' => $submission->mda_id,
            'mda' => $submission->mda?->only(['id', 'code', 'name']),
            'station_id' => $submission->station_id,
            'station' => $submission->station?->only(['id', 'code', 'name']),
            'status' => $submission->status,
            'return_reason' => $submission->return_reason,
            'summary' => $submission->summary,
            'is_late' => $submission->is_late,
            'created_by' => $submission->creator?->only(['id', 'name']),
            'submitted_by' => $submission->submitter?->only(['id', 'name']),
            'reviewed_by' => $submission->reviewer?->only(['id', 'name']),
            'approved_by' => $submission->approver?->only(['id', 'name']),
            'locked_by' => $submission->locker?->only(['id', 'name']),
            'updated_at' => $submission->updated_at,
            'submitted_at' => $submission->submitted_at,
            'approved_at' => $submission->approved_at,
            'locked_at' => $submission->locked_at,
            'template_detail' => $deep && $submission->template ? $this->templatePayload($submission->template) : null,
            'values' => $deep ? $submission->values->map(fn ($value): array => [
                'id' => $value->id,
                'indicator_id' => $value->report_template_indicator_id,
                'indicator_code' => $value->indicator_code,
                'dimension_key' => $value->dimension_key,
                'dimension_value' => $value->dimension_value,
                'value' => $value->value_integer ?? $value->value_decimal ?? $value->value_text ?? $value->value_boolean,
            ])->values() : [],
            'reviews' => $deep ? $submission->reviews->map(fn ($review): array => [
                'id' => $review->id,
                'action' => $review->action,
                'comment' => $review->comment,
                'actor' => $review->actor?->only(['id', 'name']),
                'acted_at' => $review->acted_at,
                'before_status' => $review->before_status,
                'after_status' => $review->after_status,
            ])->values() : [],
        ];
    }

    protected function indicatorPayload(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:100', 'alpha_dash'],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'value_type' => ['required', 'in:integer,decimal,percentage,text,boolean'],
            'unit' => ['nullable', 'string', 'max:50'],
            'is_required' => ['boolean'],
            'is_computed' => ['boolean'],
            'compute_formula' => ['nullable', 'array'],
            'validation_rules' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
            'dimensions' => ['sometimes', 'array'],
            'dimensions.*.dimension_key' => ['required', 'string', 'max:100', 'alpha_dash'],
            'dimensions.*.dimension_label' => ['required', 'string', 'max:255'],
            'dimensions.*.dimension_values' => ['required', 'array', 'min:1'],
            'dimensions.*.is_required' => ['boolean'],
            'dimensions.*.total_strategy' => ['nullable', 'in:none,sum_values,manual'],
            'dimensions.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
